<?php

declare(strict_types=1);

namespace Tetrix\AiBridge;

use Closure;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tetrix\AiBridge\Auth\TokenManager;
use Tetrix\AiBridge\Protocol\MessageTypes;
use Tetrix\AiBridge\Broadcasting\AiStreamEvent;
use Tetrix\AiBridge\Contracts\StreamableProvider;
use Tetrix\AiBridge\Contracts\ToolHandler;
use Tetrix\AiBridge\Enums\ProviderMode;
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
 *
 * ARCH-001 (known, deferred): This class combines several concerns — provider factory,
 * streaming coordination, SSE formatting, tool registration delegation, and broadcast
 * wiring. Extracting these into dedicated classes (e.g. StreamOutputFormatter) would
 * improve testability and reduce coupling but is a non-trivial refactor. Deferred
 * until the package reaches a stable API and a concrete maintenance pain point drives
 * the split. Future reviewers: do not re-flag this without a concrete, low-risk plan.
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
        // ARCH-004: '_broadcasting' is an internal signal set only by
        // streamAndBroadcast(). Strip it here so external callers cannot inject it
        // through the public API and silently activate broadcast-mode suppression.
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

            // UX-006: Emit the conversation_id as the first SSE event so the browser
            // can use it in subsequent multi-turn messages. Without this, an auto-generated
            // conversation_id is silently discarded, making multi-turn conversations
            // impossible to implement correctly via the SSE endpoint.
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

        // BL-003: Mark the provider as broadcasting mode before start() so that
        // relayViaHttpApi() under PHP-FPM suppresses the false bridge_sse_incompatible
        // error. Use buildStream() (not the public stream()) so the internal
        // '_broadcasting' signal is preserved rather than stripped (ARCH-004).
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

        // ARCH-011: Extract the thinking suppression check into a closure so it is
        // defined once and referenced from all three block callbacks (start/delta/stop),
        // preventing drift if the suppression logic needs to change.
        $shouldSuppressEvent = static function (StreamEvent $event) use ($suppressThinking): bool {
            return $suppressThinking && ($event->data['block_type'] ?? '') === 'thinking';
        };

        // BL-008: When thinking blocks are suppressed, re-index visible blocks starting
        // from 0 so consumers always receive a contiguous zero-based block_index sequence.
        // Without this, a suppressed thinking block (index 0) leaves the first text block
        // at index 1, which can break client rendering code that expects zero-based indexing.
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
            // ARCH-011: Wrap $sink() in try/finally so $onTerminal (the SSE [DONE] flush)
            // is always called even if the sink throws, preventing SSE clients from hanging
            // without a [DONE] terminator.
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

        // Set the provider name for routing on the bridge side.
        // CONS-010: The config key 'ai-bridge.bridge.provider' is intentionally absent from
        // ai-bridge.php because it is not currently documented or supported as a config-based
        // override. Provider selection is done via the $options['provider'] key at call time.
        // If you need config-based provider defaults, add a 'bridge.provider' key to ai-bridge.php.
        $provider = $options['provider'] ?? config('ai-bridge.bridge.provider', '');
        if (! empty($provider)) {
            $stream->setProvider($provider);
        }

        // BL-003: Propagate the broadcasting flag set by streamAndBroadcast() so that
        // relayViaHttpApi() knows to suppress the bridge_sse_incompatible false alarm.
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
        // endpoint is always read from config — never from request options (SSRF risk).
        // api_key can be overridden programmatically (e.g. BYOK mode where the consuming
        // app resolves the user's key and passes it via options). The StreamController
        // strips api_key from HTTP request input, so only server-side callers can set it.
        $endpoint = config('ai-bridge.chat_completions.endpoint');
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
