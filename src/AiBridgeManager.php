<?php

declare(strict_types=1);

namespace Tetrix\AiBridge;

use Closure;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;
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
 */
class AiBridgeManager
{
    public function __construct(
        private readonly ToolRegistry $toolRegistry,
        private readonly BridgeConnectionManager $connectionManager,
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
     *   - 'mode': ProviderMode enum instance for programmatic override (not from request input).
     *   - 'user_id': User ID for bridge mode (defaults to auth user).
     * @return StreamHandler
     */
    public function stream(string $conversationId, string $message, array $options = []): StreamHandler
    {
        $mode = $this->resolveMode($options);
        $provider = $this->createProvider($mode, $options);

        $provider->setConversationId($conversationId);
        $provider->setMessage($message);
        $provider->setOptions($options);

        $handler = $provider->getStreamHandler();
        $handler->setConversationId($conversationId);

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

            $stream->onBlockStart(function (StreamEvent $event) use ($send) {
                $send([
                    'event' => $event->event,
                    'data' => $event->data,
                ]);
            });

            $stream->onBlockDelta(function (StreamEvent $event) use ($send) {
                $send([
                    'event' => $event->event,
                    'data' => $event->data,
                ]);
            });

            $stream->onBlockStop(function (StreamEvent $event) use ($send) {
                $send([
                    'event' => $event->event,
                    'data' => $event->data,
                ]);
            });

            $stream->onToolCall(function (string $toolName, array $params, string $callId) use ($send) {
                $send([
                    'event' => 'tool_call',
                    'data' => [
                        'tool_name' => $toolName,
                        'parameters' => $params,
                        'call_id' => $callId,
                    ],
                ]);
            });

            $stream->onDone(function (?array $usage) use ($send) {
                $send([
                    'event' => 'done',
                    'data' => [
                        'usage' => $usage,
                    ],
                ]);

                echo "data: [DONE]\n\n";

                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            });

            $stream->onError(function (string $code, string $errorMessage) use ($send) {
                $send([
                    'event' => 'error',
                    'data' => [
                        'code' => $code,
                        'message' => $errorMessage,
                    ],
                ]);

                echo "data: [DONE]\n\n";

                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            });

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
        $stream = $this->stream($conversationId, $message, $options);
        $requestId = $stream->requestId;

        $broadcast = function (string $event, array $data) use ($channel, $requestId): void {
            event(new AiStreamEvent($channel, $requestId, $event, $data));
        };

        $stream->onBlockStart(function (StreamEvent $event) use ($broadcast) {
            $broadcast($event->event, $event->data);
        });

        $stream->onBlockDelta(function (StreamEvent $event) use ($broadcast) {
            $broadcast($event->event, $event->data);
        });

        $stream->onBlockStop(function (StreamEvent $event) use ($broadcast) {
            $broadcast($event->event, $event->data);
        });

        $stream->onToolCall(function (string $toolName, array $params, string $callId) use ($broadcast) {
            $broadcast('tool_call', [
                'tool_name' => $toolName,
                'parameters' => $params,
                'call_id' => $callId,
            ]);
        });

        $stream->onDone(function (?array $usage) use ($broadcast) {
            $broadcast('done', ['usage' => $usage]);
        });

        $stream->onError(function (string $code, string $errorMessage) use ($broadcast) {
            $broadcast('error', ['code' => $code, 'message' => $errorMessage]);
        });

        $stream->start();

        return $requestId;
    }

    /**
     * Get the active provider mode from config.
     */
    public function mode(): ProviderMode
    {
        return ProviderMode::from(config('ai-bridge.mode', 'byok'));
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
        $userId = $options['user_id'] ?? $this->resolveAuthUserId();

        if ($userId === null) {
            throw new InvalidArgumentException(
                'Bridge mode requires an authenticated user or a "user_id" option.'
            );
        }

        $stream = new BridgeStream(
            $this->connectionManager,
            $this->toolRegistry,
            $userId,
        );

        // Set the provider name for routing on the bridge side
        $provider = $options['provider'] ?? config('ai-bridge.bridge.provider', '');
        if (! empty($provider)) {
            $stream->setProvider($provider);
        }

        return $stream;
    }

    /**
     * Create a ChatCompletionsStream provider.
     */
    private function createChatCompletionsProvider(array $options): ChatCompletionsStream
    {
        // SEC: endpoint and api_key are always read from config — never from request options.
        // Accepting these from the client would enable SSRF (endpoint) and credential override (api_key).
        $endpoint = config('ai-bridge.chat_completions.endpoint');
        $apiKey = config('ai-bridge.chat_completions.api_key');
        $model = $options['model'] ?? config('ai-bridge.chat_completions.model');
        $maxTokens = $options['max_tokens'] ?? (int) config('ai-bridge.chat_completions.max_tokens', 4096);

        if (empty($endpoint)) {
            throw new InvalidArgumentException(
                'Chat Completions endpoint is not configured. Set AI_BRIDGE_ENDPOINT in your .env file.'
            );
        }

        if (empty($apiKey)) {
            throw new InvalidArgumentException(
                'Chat Completions API key is not configured. Set AI_BRIDGE_API_KEY in your .env file.'
            );
        }

        if (empty($model)) {
            throw new InvalidArgumentException(
                'Chat Completions model is not configured. Set AI_BRIDGE_MODEL in your .env file.'
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
