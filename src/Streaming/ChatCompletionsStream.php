<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Streaming;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Tetrix\AiBridge\Contracts\StreamableProvider;
use Tetrix\AiBridge\Enums\BlockType;
use Tetrix\AiBridge\Tools\ToolRegistry;

/**
 * Streams AI responses via the Chat Completions API (OpenAI-compatible).
 *
 * Used in both BYOK (user provides API key) and managed (app provides key) modes.
 * Normalizes the SSE stream from the Chat Completions API into the unified
 * StreamEvent format used by the rest of the package.
 */
class ChatCompletionsStream implements StreamableProvider
{
    private string $conversationId = '';

    private string $message = '';

    /** @var array<string, mixed> */
    private array $options = [];

    private StreamHandler $streamHandler;

    private bool $cancelled = false;

    public function __construct(
        private readonly ToolRegistry $toolRegistry,
        private readonly string $endpoint,
        private readonly string $apiKey,
        private readonly string $model,
        private readonly int $maxTokens,
    ) {
        $this->streamHandler = new StreamHandler($this);
    }

    public function setConversationId(string $conversationId): static
    {
        $this->conversationId = $conversationId;

        return $this;
    }

    public function setMessage(string $message): static
    {
        $this->message = $message;

        return $this;
    }

    public function setOptions(array $options): static
    {
        $this->options = $options;

        return $this;
    }

    public function getStreamHandler(): StreamHandler
    {
        return $this->streamHandler;
    }

    /**
     * Build the request body for the Chat Completions API.
     *
     * @return array<string, mixed>
     */
    public function buildRequestBody(): array
    {
        $messages = [];

        // System prompt
        if (isset($this->options['system_prompt'])) {
            $messages[] = [
                'role' => 'system',
                'content' => $this->options['system_prompt'],
            ];
        }

        // Conversation history, if provided. Filter out client-supplied messages
        // with role='system' to prevent injection of system-role instructions
        // that could undermine the operator's system prompt.
        if (isset($this->options['messages']) && is_array($this->options['messages'])) {
            $filtered = array_values(array_filter(
                $this->options['messages'],
                fn ($msg) => is_array($msg) && ($msg['role'] ?? '') !== 'system'
            ));
            $messages = array_merge($messages, $filtered);
        }

        // Current user message
        $messages[] = [
            'role' => 'user',
            'content' => $this->message,
        ];

        $body = [
            'model' => $this->options['model'] ?? $this->model,
            'messages' => $messages,
            'max_tokens' => $this->options['max_tokens'] ?? $this->maxTokens,
            'stream' => true,
            'stream_options' => ['include_usage' => true],
        ];

        // Temperature
        if (isset($this->options['temperature'])) {
            $body['temperature'] = $this->options['temperature'];
        }

        // Tools
        $tools = $this->toolRegistry->toFunctionSchemas();
        if (! empty($tools)) {
            $body['tools'] = $tools;
            $body['tool_choice'] = 'auto';
        }

        return $body;
    }

    public function start(): void
    {
        $this->cancelled = false;

        // Strip any trailing /v1 from the endpoint to prevent double /v1/v1/... paths.
        // A common misconfiguration is setting the endpoint to https://api.openai.com/v1
        // instead of https://api.openai.com — we detect and correct this silently so the
        // request still succeeds, and log a warning so the operator can fix their config.
        $endpoint = rtrim($this->endpoint, '/');
        if (str_ends_with($endpoint, '/v1')) {
            Log::warning('AI Bridge: endpoint already contains /v1 suffix — stripping it to avoid double /v1/v1 path. Update AI_BRIDGE_ENDPOINT to omit the trailing /v1.', [
                'endpoint' => $endpoint,
            ]);
            $endpoint = substr($endpoint, 0, -3);
        }
        $url = $endpoint.'/v1/chat/completions';
        $body = $this->buildRequestBody();

        try {
            /** @var \Illuminate\Http\Client\Response $response */
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'text/event-stream',
            ])
                ->timeout((int) config('ai-bridge.chat_completions.stream_timeout', 300))
                ->withOptions(['stream' => true])
                ->post($url, $body);

            if ($response->failed()) {
                Log::error('AI Bridge: Chat Completions API error', [
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 1000),
                ]);

                // Map HTTP status to actionable error codes and messages
                [$errorCode, $errorMessage] = match ($response->status()) {
                    429 => ['rate_limited', 'AI provider rate limit exceeded. Please wait and try again.'],
                    401, 403 => ['auth_error', 'AI provider authentication failed. Check your API key configuration.'],
                    503 => ['service_unavailable', 'AI provider is temporarily unavailable. Try again shortly.'],
                    default => ['api_error', 'AI provider returned an error (HTTP '.$response->status().'). Check server logs for details.'],
                };

                $this->streamHandler->dispatchError($errorCode, $errorMessage);

                return;
            }

            $this->processStream($response);
        } catch (\Exception $e) {
            Log::error('AI Bridge: Chat Completions request failed', [
                'error' => $e->getMessage(),
            ]);

            $this->streamHandler->dispatchError(
                'request_failed',
                'Failed to connect to AI provider. Check server logs for details.'
            );
        }
    }

    public function markCompleted(): void
    {
        // No-op: ChatCompletionsStream is synchronous and doesn't need completion tracking.
    }

    public function cancel(): void
    {
        $this->cancelled = true;
    }

    /**
     * Flush accumulated tool calls to the stream handler (at most once).
     *
     * @param  array  &$accumulators  The accumulated tool call data.
     * @param  bool  &$dispatched  Guard flag, set to true after first flush.
     */
    private function flushToolCalls(array &$accumulators, bool &$dispatched): void
    {
        if ($dispatched || empty($accumulators)) {
            return;
        }

        foreach ($accumulators as $accum) {
            $params = json_decode($accum['arguments'], true) ?? [];
            $this->streamHandler->dispatchToolCall(
                $accum['name'],
                $params,
                $accum['id'],
            );
        }

        $dispatched = true;
        $accumulators = [];
    }

    /**
     * Process the SSE stream from the Chat Completions API.
     *
     * Parses SSE events, normalizes them into StreamHandler dispatch calls.
     */
    private function processStream(Response $response): void
    {
        $body = $response->toPsrResponse()->getBody();
        $buffer = '';
        $blockIndex = 0;
        $currentBlockType = null;
        $toolCallAccumulators = []; // Accumulate tool call arguments across deltas
        $toolCallsDispatched = false; // Guard against dispatching tool calls more than once

        while (! $body->eof() && ! $this->cancelled) {
            $chunk = $body->read(8192);
            if ($chunk === '' || $chunk === false) {
                continue;
            }

            $buffer .= $chunk;

            // Process complete SSE lines
            while (($newlinePos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $newlinePos);
                $buffer = substr($buffer, $newlinePos + 1);
                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                if (! str_starts_with($line, 'data: ')) {
                    continue;
                }

                $data = substr($line, 6);

                if ($data === '[DONE]') {
                    // Close any open block
                    if ($currentBlockType !== null) {
                        $this->streamHandler->dispatchBlockStop($currentBlockType, $blockIndex);
                        $currentBlockType = null;
                    }

                    $this->flushToolCalls($toolCallAccumulators, $toolCallsDispatched);

                    $this->streamHandler->dispatchDone(null);

                    return;
                }

                $parsed = json_decode($data, true);
                if (! is_array($parsed) || empty($parsed['choices'])) {
                    // Check for usage-only final chunk
                    if (is_array($parsed) && isset($parsed['usage'])) {
                        // Close any open block
                        if ($currentBlockType !== null) {
                            $this->streamHandler->dispatchBlockStop($currentBlockType, $blockIndex);
                            $currentBlockType = null;
                        }

                        $this->flushToolCalls($toolCallAccumulators, $toolCallsDispatched);

                        $this->streamHandler->dispatchDone($parsed['usage']);

                        return;
                    }

                    continue;
                }

                $choice = $parsed['choices'][0] ?? null;
                if (! $choice) {
                    continue;
                }

                $delta = $choice['delta'] ?? [];

                // Handle tool calls in delta
                if (isset($delta['tool_calls'])) {
                    // If we have an open text block, close it first
                    if ($currentBlockType === BlockType::Text) {
                        $this->streamHandler->dispatchBlockStop(BlockType::Text, $blockIndex);
                        $currentBlockType = null;
                    }

                    foreach ($delta['tool_calls'] as $toolCallDelta) {
                        $tcIndex = $toolCallDelta['index'] ?? 0;

                        if (isset($toolCallDelta['id'])) {
                            // New tool call starting
                            $toolCallAccumulators[$tcIndex] = [
                                'id' => $toolCallDelta['id'],
                                'name' => $toolCallDelta['function']['name'] ?? '',
                                'arguments' => $toolCallDelta['function']['arguments'] ?? '',
                            ];
                        } else {
                            // Accumulating arguments
                            if (isset($toolCallAccumulators[$tcIndex])) {
                                $toolCallAccumulators[$tcIndex]['arguments'] .= $toolCallDelta['function']['arguments'] ?? '';
                            }
                        }
                    }

                    continue;
                }

                // Handle content delta
                $content = $delta['content'] ?? null;
                if ($content !== null && $content !== '') {
                    if ($currentBlockType !== BlockType::Text) {
                        // Close previous block if different type
                        if ($currentBlockType !== null) {
                            $this->streamHandler->dispatchBlockStop($currentBlockType, $blockIndex);
                            $blockIndex++;
                        }

                        $currentBlockType = BlockType::Text;
                        $this->streamHandler->dispatchBlockStart(BlockType::Text, $blockIndex);
                    }

                    $this->streamHandler->dispatchBlockDelta(BlockType::Text, $blockIndex, $content);
                }

                // Handle finish reason
                $finishReason = $choice['finish_reason'] ?? null;
                if ($finishReason !== null) {
                    if ($currentBlockType !== null) {
                        $this->streamHandler->dispatchBlockStop($currentBlockType, $blockIndex);
                        $currentBlockType = null;
                    }

                    // Dispatch any accumulated tool calls regardless of finish_reason value.
                    // Some providers use 'tool_calls', others use 'stop' even when tool calls are present.
                    $this->flushToolCalls($toolCallAccumulators, $toolCallsDispatched);

                    // Usage may come in the same or next chunk, so don't dispatch done yet
                    // unless this is a non-streaming finish
                }
            }
        }

        // If we reach here, the stream ended without a proper [DONE] sentinel.
        // Close any open block and dispatch an appropriate terminal event.
        if ($currentBlockType !== null) {
            $this->streamHandler->dispatchBlockStop($currentBlockType, $blockIndex);
        }

        if ($this->cancelled) {
            $this->streamHandler->dispatchCancelled('Stream was cancelled by the user.');
        } else {
            // Flush accumulated tool calls before dispatching the error so
            // partially-streamed tool call arguments are not silently discarded.
            $this->flushToolCalls($toolCallAccumulators, $toolCallsDispatched);

            // Stream ended without [DONE] — likely a connection drop or upstream error.
            // Dispatch error so SSE/Reverb consumers don't hang indefinitely.
            $this->streamHandler->dispatchError(
                'stream_incomplete',
                'Chat Completions stream ended without a [DONE] sentinel. The connection may have dropped.'
            );
        }
    }
}
