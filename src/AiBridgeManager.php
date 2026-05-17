<?php

declare(strict_types=1);

namespace Tetrix\AiBridge;

use Closure;
use InvalidArgumentException;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tetrix\AiBridge\Auth\TokenManager;
use Tetrix\AiBridge\Protocol\MessageTypes;
use Tetrix\AiBridge\Broadcasting\AiStreamEvent;
use Tetrix\AiBridge\Contracts\StreamableProvider;
use Tetrix\AiBridge\Contracts\ToolHandler;
use Tetrix\AiBridge\Enums\ProviderMode;
use Tetrix\AiBridge\Models\Conversation;
use Tetrix\AiBridge\Models\Message;
use Tetrix\AiBridge\Protocol\StreamEvent;
use Tetrix\AiBridge\Streaming\BridgeStream;
use Tetrix\AiBridge\Streaming\ChatCompletionsStream;
use Tetrix\AiBridge\Streaming\StreamHandler;
use Tetrix\AiBridge\Tools\ToolRegistry;
use Tetrix\AiBridge\WebSocket\BridgeConnectionManager;

/**
 * Main manager class — the unified interface for the AI Bridge package.
 *
 * This is the class behind the AiBridge facade. It provides the primary
 * API for consuming applications:
 *
 *   $stream = AiBridge::stream($conversationId, $message, ['system_prompt' => '...']);
 *   $stream->onBlockDelta(fn (StreamEvent $e) => echo $e->data['content']);
 *   $stream->onDone(fn (?array $usage) => logger('Done!'));
 *   $stream->start();
 *
 * The manager determines which provider to use based on configuration and
 * creates the appropriate StreamableProvider implementation.
 */
class AiBridgeManager
{
    public function __construct(
        private readonly ToolRegistry $toolRegistry,
        private readonly BridgeConnectionManager $connectionManager,
        private readonly TokenManager $tokenManager,
    ) {}

    /**
     * Create a new streaming session for an AI request.
     *
     * Returns a StreamHandler with the appropriate provider configured.
     * The consuming app registers callbacks on the StreamHandler, then calls start().
     *
     * @param  string  $conversationId  Unique conversation identifier.
     * @param  string  $message  The user's message to send to the AI.
     * @param  array<string, mixed>  $options  Additional options:
     *   - 'system_prompt': System prompt for the AI.
     *   - 'messages': Conversation history (array of role/content pairs).
     *   - 'temperature': Sampling temperature.
     *   - 'max_tokens': Maximum tokens in response.
     *   - 'model': Override the configured model.
     *   - 'api_key': Override API key for BYOK (server-side only, stripped from HTTP input by StreamController).
     *   - 'mode': ProviderMode enum instance (server-side only, stripped from HTTP input by StreamController).
     *   - 'user_id': User ID for bridge mode (server-side only, stripped from HTTP input by StreamController).
     * @return StreamHandler
     */
    public function stream(string $conversationId, string $message, array $options = []): StreamHandler
    {
        // '_broadcasting' is an internal signal set only by streamAndBroadcast().
        // Strip it so external callers cannot inject it through the public API.
        unset($options['_broadcasting']);

        return $this->buildStream($conversationId, $message, $options);
    }

    /**
     * Build a configured StreamHandler from the given options.
     *
     * Internal counterpart of stream() that does NOT strip the '_broadcasting'
     * key, so streamAndBroadcast() can pass it through to createBridgeProvider().
     *
     * @param  array<string, mixed>  $options
     */
    private function buildStream(string $conversationId, string $message, array $options): StreamHandler
    {
        $mode = $this->resolveMode($options);
        $provider = $this->createProvider($mode, $options);

        $provider->setConversationId($conversationId);
        $provider->setMessage($message);
        $provider->setOptions($options);

        $handler = $provider->getStreamHandler();
        $handler->setConversationId($conversationId);
        $handler->setMode($mode);

        return $handler;
    }

    /**
     * Register a tool the AI can call.
     *
     * @param  string  $name  Unique tool name.
     * @param  string  $description  Human-readable description.
     * @param  array<string, mixed>  $parameters  JSON Schema for parameters.
     * @param  Closure  $handler  The function that executes the tool.
     * @return $this
     */
    public function registerTool(string $name, string $description, array $parameters, Closure $handler): static
    {
        $this->toolRegistry->register($name, $description, $parameters, $handler);

        return $this;
    }

    /**
     * Register a tool from a ToolHandler class.
     *
     * @param  ToolHandler  $handler
     * @return $this
     */
    public function registerToolHandler(ToolHandler $handler): static
    {
        $this->toolRegistry->registerHandler($handler);

        return $this;
    }

    /**
     * Get the tool registry.
     */
    public function tools(): ToolRegistry
    {
        return $this->toolRegistry;
    }

    /**
     * Get the bridge connection manager.
     */
    public function connections(): BridgeConnectionManager
    {
        return $this->connectionManager;
    }

    /**
     * Check if a user has an active bridge connection.
     */
    public function hasBridge(int|string $userId): bool
    {
        return $this->connectionManager->hasConnection($userId);
    }

    /**
     * SSE streaming — returns a StreamedResponse that delivers normalized
     * AI events as Server-Sent Events.
     *
     * Each event is sent as: data: {"event": "...", "data": {...}}
     * The stream ends with: data: [DONE]
     *
     * @param  string  $conversationId  Unique conversation identifier.
     * @param  string  $message  The user's message to send to the AI.
     * @param  array<string, mixed>  $options  Additional options (system_prompt, temperature, etc.).
     * @return StreamedResponse
     */
    public function streamToResponse(string $conversationId, string $message, array $options = []): StreamedResponse
    {
        return new StreamedResponse(function () use ($conversationId, $message, $options) {
            $stream = $this->stream($conversationId, $message, $options);

            $send = function (array $payload): void {
                echo 'data: ' . json_encode($payload) . "\n\n";

                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            };

            $sendTerminal = function () {
                echo "data: [DONE]\n\n";

                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            };

            $this->wireCallbacks($stream, $send, $sendTerminal);

            // Emit the conversation_id as the first SSE event so the browser can
            // use it in subsequent multi-turn messages — an auto-generated id
            // would otherwise be lost.
            $send(['event' => 'conversation_id', 'data' => ['conversation_id' => $conversationId]]);

            $stream->start();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Reverb broadcasting — starts a stream and broadcasts each event
     * to the specified Reverb channel. Returns immediately with a request ID.
     *
     * Events are broadcast as "ai.stream" events on the given channel.
     * The consuming app can listen via Laravel Echo / Reverb.
     *
     * @param  string  $conversationId  Unique conversation identifier.
     * @param  string  $message  The user's message to send to the AI.
     * @param  string  $channel  The broadcast channel name (e.g. "game.123").
     * @param  array<string, mixed>  $options  Additional options (system_prompt, temperature, etc.).
     * @return string  The request ID for this stream.
     */
    public function streamAndBroadcast(string $conversationId, string $message, string $channel, array $options = []): string
    {
        if (! config('ai-bridge.broadcasting.enabled', true)) {
            throw new InvalidArgumentException(
                'Broadcasting is disabled. Set AI_BRIDGE_BROADCAST=true in .env or use streamToResponse() for SSE.'
            );
        }

        // Mark the provider as broadcasting mode before start() so relayViaHttpApi()
        // under PHP-FPM suppresses the false bridge_sse_incompatible error. Use
        // buildStream() (not stream()) so the '_broadcasting' signal is preserved.
        $options['_broadcasting'] = true;
        $stream = $this->buildStream($conversationId, $message, $options);
        $requestId = $stream->requestId;

        $broadcast = function (array $payload) use ($channel, $requestId): void {
            event(new AiStreamEvent($channel, $requestId, $payload['event'], $payload['data']));
        };

        $this->wireCallbacks($stream, $broadcast);

        // For BYOK/managed modes (ChatCompletionsStream), start() blocks until the
        // HTTP stream completes. Use afterResponse() to run the stream after the
        // HTTP response (with the request_id/channel) has been sent to the client.
        // For bridge mode, start() is already non-blocking, but afterResponse()
        // is safe to use there too.
        dispatch(function () use ($stream) {
            $stream->start();
        })->afterResponse();

        return $requestId;
    }

    // ── Conversation streaming ────────────────────────────────────────

    /**
     * Build a configured StreamHandler for a persisted conversation.
     *
     * Persists the user turn, injects prior history + the conversation's
     * provider/model/mode/system_prompt, and attaches persistence so the
     * assistant turn is written when the stream completes.
     *
     * @param  array<string, mixed>  $options  Extra options (rarely needed —
     *   provider/model/mode/system_prompt come from the conversation).
     */
    public function streamConversation(Conversation $conversation, string $message, array $options = []): StreamHandler
    {
        $conversation->loadMissing('messages', 'connection');

        // Capture prior history BEFORE persisting the new user turn.
        $history = $conversation->historyFor();

        // Persist the user turn immediately.
        $conversation->appendMessage(Message::ROLE_USER, $message);

        $mode = ProviderMode::from($conversation->mode);
        $options['mode'] = $mode;
        $options['messages'] = $history;

        if (! empty($conversation->system_prompt)) {
            $options['system_prompt'] = $conversation->system_prompt;
        }
        if (! empty($conversation->model)) {
            $options['model'] = $conversation->model;
        }

        $connection = $conversation->connection;

        if ($mode === ProviderMode::Bridge) {
            $options['provider'] = $conversation->provider ?? '';
            if ($connection !== null && ! empty($connection->connection_key)) {
                $options['user_id'] = $connection->connection_key;
            }
        } elseif ($mode === ProviderMode::Byok && $connection !== null) {
            if (! empty($connection->endpoint)) {
                $options['endpoint'] = $connection->endpoint;
            }
            if (! empty($connection->api_key)) {
                $options['api_key'] = $connection->api_key;
            }
        }

        $handler = $this->buildStream((string) $conversation->id, $message, $options);

        $this->attachConversationPersistence($handler, $conversation);

        return $handler;
    }

    /**
     * SSE streaming for a persisted conversation (BYOK / Managed modes).
     */
    public function streamConversationToResponse(Conversation $conversation, string $message, array $options = []): StreamedResponse
    {
        return new StreamedResponse(function () use ($conversation, $message, $options) {
            $stream = $this->streamConversation($conversation, $message, $options);

            $send = function (array $payload): void {
                echo 'data: ' . json_encode($payload) . "\n\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            };
            $sendTerminal = function (): void {
                echo "data: [DONE]\n\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            };

            $this->wireCallbacks($stream, $send, $sendTerminal);

            $send(['event' => 'conversation_id', 'data' => ['conversation_id' => (string) $conversation->id]]);

            $stream->start();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Reverb-broadcast streaming for a persisted conversation (Bridge mode).
     *
     * Broadcasts on the per-conversation private channel. Returns the request ID.
     */
    public function streamConversationAndBroadcast(Conversation $conversation, string $message, array $options = []): string
    {
        if (! config('ai-bridge.broadcasting.enabled', true)) {
            throw new InvalidArgumentException(
                'Broadcasting is disabled. Set AI_BRIDGE_BROADCAST=true in .env.'
            );
        }

        $channel = config('ai-bridge.persistence.channel_prefix', 'ai-bridge.conversation')
            . '.' . $conversation->id;

        $options['_broadcasting'] = true;
        $stream = $this->streamConversation($conversation, $message, $options);
        $requestId = $stream->requestId;

        $broadcast = function (array $payload) use ($channel, $requestId): void {
            event(new AiStreamEvent($channel, $requestId, $payload['event'], $payload['data']));
        };

        $this->wireCallbacks($stream, $broadcast);

        dispatch(function () use ($stream) {
            $stream->start();
        })->afterResponse();

        return $requestId;
    }

    /**
     * Attach persistence callbacks that accumulate the assistant block stream
     * and write the assistant turn when the stream terminates.
     *
     * Captures the RAW block stream — including thinking blocks — for faithful
     * UI replay. This is independent of wireCallbacks() (which re-indexes and
     * may suppress thinking for the SSE/broadcast sink).
     */
    private function attachConversationPersistence(StreamHandler $handler, Conversation $conversation): void
    {
        /** @var array<int, array<string, mixed>> $blocks */
        $blocks = [];
        /** @var array<string, mixed>|null $current */
        $current = null;

        $handler->onBlockStart(function (StreamEvent $event) use (&$current) {
            $current = ['type' => $event->data['block_type'] ?? 'text', 'text' => ''];
        });
        $handler->onBlockDelta(function (StreamEvent $event) use (&$current) {
            if ($current !== null) {
                $current['text'] .= $event->data['content'] ?? '';
            }
        });
        $handler->onBlockStop(function () use (&$blocks, &$current) {
            if ($current !== null) {
                $blocks[] = $current;
                $current = null;
            }
        });
        $handler->onToolCall(function (string $name, array $params, string $callId) use (&$blocks) {
            $blocks[] = ['type' => 'tool_call', 'tool_name' => $name, 'parameters' => $params, 'tool_call_id' => $callId];
        });
        $handler->onToolResult(function (string $callId, mixed $result) use (&$blocks) {
            $blocks[] = ['type' => 'tool_result', 'tool_call_id' => $callId, 'result' => $result];
        });
        $handler->onDone(function (?array $usage) use (&$blocks, &$current, $conversation) {
            if ($current !== null) {
                $blocks[] = $current;
                $current = null;
            }
            $this->persistAssistantTurn($conversation, $blocks, $usage, false);
        });

        $persistPartial = function () use (&$blocks, &$current, $conversation) {
            if ($current !== null) {
                $blocks[] = $current;
                $current = null;
            }
            if (config('ai-bridge.persistence.persist_partial_on_error', true)
                && $this->blocksHaveContent($blocks)) {
                $this->persistAssistantTurn($conversation, $blocks, null, true);
            }
        };
        $handler->onError(fn () => $persistPartial());
        $handler->onCancelled(fn () => $persistPartial());
    }

    /**
     * Persist an assistant turn from accumulated blocks.
     *
     * @param  array<int, array<string, mixed>>  $blocks
     * @param  array<string, mixed>|null  $usage
     */
    private function persistAssistantTurn(Conversation $conversation, array $blocks, ?array $usage, bool $incomplete): void
    {
        // Flat content is the concatenation of text blocks only.
        $text = '';
        foreach ($blocks as $block) {
            if (($block['type'] ?? 'text') === 'text') {
                $text .= $block['text'] ?? '';
            }
        }

        try {
            $conversation->appendMessage(Message::ROLE_ASSISTANT, $text, [
                'blocks' => $blocks,
                'provider' => $conversation->provider,
                'model' => $conversation->model,
                'usage' => $usage,
                'incomplete' => $incomplete,
            ]);
        } catch (\Throwable $e) {
            Log::error('AI Bridge: failed to persist assistant turn', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Whether the accumulated blocks contain any meaningful content.
     *
     * @param  array<int, array<string, mixed>>  $blocks
     */
    private function blocksHaveContent(array $blocks): bool
    {
        foreach ($blocks as $block) {
            if (($block['type'] ?? '') === 'tool_call' || ($block['text'] ?? '') !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Wire all seven stream callbacks to a sink callable.
     *
     * Callbacks: onBlockStart, onBlockDelta, onBlockStop, onToolCall, onDone, onError, onCancelled.
     * The $sink receives a normalized payload array with 'event' and 'data' keys.
     * The optional $onTerminal callback is called after done/error/cancelled events (e.g. for SSE [DONE] flush).
     */
    private function wireCallbacks(StreamHandler $stream, callable $sink, ?Closure $onTerminal = null): void
    {
        $suppressThinking = (bool) config('ai-bridge.streaming.suppress_thinking_blocks', true);

        // Thinking suppression check shared by all three block callbacks.
        $shouldSuppressEvent = static function (StreamEvent $event) use ($suppressThinking): bool {
            return $suppressThinking && ($event->data['block_type'] ?? '') === 'thinking';
        };

        // When thinking blocks are suppressed, re-index visible blocks from 0 so
        // consumers always receive a contiguous zero-based block_index sequence.
        $visibleBlockCounter = 0;
        // Track whether a block is currently suppressed so delta/stop share the same decision.
        $currentBlockSuppressed = false;

        $stream->onBlockStart(function (StreamEvent $event) use ($sink, $shouldSuppressEvent, &$visibleBlockCounter, &$currentBlockSuppressed) {
            $currentBlockSuppressed = $shouldSuppressEvent($event);
            if ($currentBlockSuppressed) {
                return;
            }
            $data = $event->data;
            $data['block_index'] = $visibleBlockCounter;
            $sink(['event' => $event->event, 'data' => $data]);
        });

        $stream->onBlockDelta(function (StreamEvent $event) use ($sink, &$currentBlockSuppressed, &$visibleBlockCounter) {
            if ($currentBlockSuppressed) {
                return;
            }
            $data = $event->data;
            $data['block_index'] = $visibleBlockCounter;
            $sink(['event' => $event->event, 'data' => $data]);
        });

        $stream->onBlockStop(function (StreamEvent $event) use ($sink, &$currentBlockSuppressed, &$visibleBlockCounter) {
            if ($currentBlockSuppressed) {
                $currentBlockSuppressed = false;

                return;
            }
            $data = $event->data;
            $data['block_index'] = $visibleBlockCounter;
            $sink(['event' => $event->event, 'data' => $data]);
            $visibleBlockCounter++;
        });

        $stream->onToolCall(function (string $toolName, array $params, string $callId) use ($sink) {
            $sink([
                'event' => MessageTypes::TOOL_CALL,
                'data' => [
                    'tool_name' => $toolName,
                    'parameters' => $params,
                    'tool_call_id' => $callId,
                    'call_id' => $callId, // Deprecated: use tool_call_id instead
                ],
            ]);
        });

        $stream->onDone(function (?array $usage) use ($sink, $onTerminal) {
            // Wrap $sink() in try/finally so $onTerminal (the SSE [DONE] flush)
            // always runs even if the sink throws, preventing SSE clients from hanging.
            try {
                $sink(['event' => MessageTypes::DONE, 'data' => ['usage' => $usage]]);
            } finally {
                if ($onTerminal) {
                    $onTerminal();
                }
            }
        });

        $stream->onError(function (string $code, string $errorMessage) use ($sink, $onTerminal) {
            try {
                $sink(['event' => MessageTypes::ERROR, 'data' => ['code' => $code, 'message' => $errorMessage]]);
            } finally {
                if ($onTerminal) {
                    $onTerminal();
                }
            }
        });

        $stream->onCancelled(function (string $reason) use ($sink, $onTerminal) {
            try {
                $sink(['event' => MessageTypes::CANCELLED, 'data' => ['reason' => $reason]]);
            } finally {
                if ($onTerminal) {
                    $onTerminal();
                }
            }
        });
    }

    // ── Conversation scoping resolvers ────────────────────────────────

    /**
     * Project-supplied resolver returning the query of conversations visible
     * to the current request. The package owns no ownership concept — the
     * consuming app registers this in a service provider's boot().
     *
     * @var Closure|null
     */
    private ?Closure $conversationsResolver = null;

    /**
     * Project-supplied resolver returning the query of connections visible
     * to the current request.
     *
     * @var Closure|null
     */
    private ?Closure $connectionsResolver = null;

    /**
     * Register the resolver that scopes which conversations a request may see.
     *
     * The closure receives the current Request and returns an Eloquent
     * Builder for {@see \Tetrix\AiBridge\Models\Conversation}.
     */
    public function resolveConversationsUsing(Closure $resolver): static
    {
        $this->conversationsResolver = $resolver;

        return $this;
    }

    /**
     * Register the resolver that scopes which connections a request may see.
     */
    public function resolveConnectionsUsing(Closure $resolver): static
    {
        $this->connectionsResolver = $resolver;

        return $this;
    }

    /**
     * Get the scoped conversations query for a request.
     *
     * Secure-by-default: when the consuming app has not registered a resolver,
     * this returns an empty query so conversations are never exposed by accident.
     */
    public function conversationsQuery(\Illuminate\Http\Request $request): \Illuminate\Database\Eloquent\Builder
    {
        if ($this->conversationsResolver !== null) {
            return ($this->conversationsResolver)($request);
        }

        \Illuminate\Support\Facades\Log::warning('AI Bridge: no conversations resolver registered — denying all access. Call AiBridge::resolveConversationsUsing() in a service provider.');

        return \Tetrix\AiBridge\Models\Conversation::query()->whereRaw('1 = 0');
    }

    /**
     * Get the scoped connections query for a request.
     */
    public function connectionsQuery(\Illuminate\Http\Request $request): \Illuminate\Database\Eloquent\Builder
    {
        if ($this->connectionsResolver !== null) {
            return ($this->connectionsResolver)($request);
        }

        \Illuminate\Support\Facades\Log::warning('AI Bridge: no connections resolver registered — denying all access. Call AiBridge::resolveConnectionsUsing() in a service provider.');

        return \Tetrix\AiBridge\Models\Connection::query()->whereRaw('1 = 0');
    }

    /**
     * Get the active provider mode from config.
     *
     * @throws InvalidArgumentException If the configured mode is not a valid ProviderMode value.
     */
    public function mode(): ProviderMode
    {
        $modeValue = config('ai-bridge.mode', 'byok');

        try {
            return ProviderMode::from($modeValue);
        } catch (\ValueError) {
            $allowed = implode(', ', array_map(fn (ProviderMode $m) => "'{$m->value}'", ProviderMode::cases()));
            throw new InvalidArgumentException(
                "Invalid AI_BRIDGE_MODE value '{$modeValue}'. Allowed values: {$allowed}."
            );
        }
    }

    /**
     * Resolve the provider mode from configuration.
     *
     * Mode is always determined server-side from config — never from request input.
     * The $options parameter accepts ProviderMode enum instances for programmatic
     * override only (e.g. when the consuming app explicitly passes a mode).
     */
    private function resolveMode(array $options): ProviderMode
    {
        // Only accept ProviderMode enum instances for programmatic override,
        // not arbitrary string values from request input.
        if (isset($options['mode']) && $options['mode'] instanceof ProviderMode) {
            return $options['mode'];
        }

        return $this->mode();
    }

    /**
     * Create the appropriate StreamableProvider for the given mode.
     *
     * @throws InvalidArgumentException If required configuration is missing.
     */
    private function createProvider(ProviderMode $mode, array $options): StreamableProvider
    {
        return match ($mode) {
            ProviderMode::Bridge => $this->createBridgeProvider($options),
            ProviderMode::Byok, ProviderMode::Managed => $this->createChatCompletionsProvider($options),
        };
    }

    /**
     * Create a BridgeStream provider.
     */
    private function createBridgeProvider(array $options): BridgeStream
    {
        // SEC: user_id is stripped from HTTP request input by StreamController.
        // Programmatic callers (jobs, artisan commands) can pass user_id via options.
        $userId = $options['user_id'] ?? $this->resolveAuthUserId();

        if ($userId === null) {
            throw new InvalidArgumentException(
                'Bridge mode requires an authenticated user or a "user_id" option.'
            );
        }

        $stream = new BridgeStream(
            $this->connectionManager,
            $this->toolRegistry,
            $this->tokenManager,
            $userId,
        );

        // Set the provider name for routing on the bridge side. Provider selection
        // is done via the $options['provider'] key at call time; the
        // 'ai-bridge.bridge.provider' config key is an undocumented fallback.
        $provider = $options['provider'] ?? config('ai-bridge.bridge.provider', '');
        if (! empty($provider)) {
            $stream->setProvider($provider);
        }

        // Propagate the broadcasting flag set by streamAndBroadcast() so
        // relayViaHttpApi() suppresses the bridge_sse_incompatible false alarm.
        if (! empty($options['_broadcasting'])) {
            $stream->setBroadcastingMode(true);
        }

        return $stream;
    }

    /**
     * Create a ChatCompletionsStream provider.
     */
    private function createChatCompletionsProvider(array $options): ChatCompletionsStream
    {
        // endpoint and api_key may be overridden programmatically (e.g. per-conversation
        // BYOK, where streamConversation() resolves them from a server-side Connection
        // row). The StreamController strips both from HTTP request input, so only
        // server-side callers can set them — guarding against SSRF / key injection.
        $endpoint = $options['endpoint'] ?? config('ai-bridge.chat_completions.endpoint');
        $apiKey = $options['api_key'] ?? config('ai-bridge.chat_completions.api_key');
        $model = $options['model'] ?? config('ai-bridge.chat_completions.model');
        $maxTokens = $options['max_tokens'] ?? (int) config('ai-bridge.chat_completions.max_tokens', 4096);

        if (empty($endpoint)) {
            throw new InvalidArgumentException(
                'Chat Completions endpoint is not configured. Set AI_BRIDGE_ENDPOINT in .env (e.g. https://api.openai.com), or pass endpoint via config.'
            );
        }

        if (empty($apiKey)) {
            throw new InvalidArgumentException(
                'Chat Completions API key is not configured. Set AI_BRIDGE_API_KEY in .env, or pass api_key in the options array for per-user BYOK.'
            );
        }

        if (empty($model)) {
            throw new InvalidArgumentException(
                'Chat Completions model is not configured. Set AI_BRIDGE_MODEL in .env (e.g. gpt-4o).'
            );
        }

        return new ChatCompletionsStream(
            $this->toolRegistry,
            $endpoint,
            $apiKey,
            $model,
            (int) $maxTokens,
        );
    }

    /**
     * Resolve the authenticated user's ID from the request context.
     */
    private function resolveAuthUserId(): int|string|null
    {
        $user = auth()->user();

        return $user?->getAuthIdentifier();
    }
}
