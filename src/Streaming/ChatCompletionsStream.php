<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Streaming;

use Illuminate\Http\Client\Response;
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

        // Conversation history, if provided
        if (isset($this->options['messages']) && is_array($this->options['messages'])) {
            $messages = array_merge($messages, $this->options['messages']);
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

        $url = rtrim($this->endpoint, '/').'/v1/chat/completions';
        $body = $this->buildRequestBody();

        try {
            /** @var \Illuminate\Http\Client\Response $response */
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'text/event-stream',
            ])
                ->timeout((int) config('ai-bridge.websocket.request_timeout', 300))
                ->withOptions(['stream' => true])
                ->post($url, $body);

            if ($response->failed()) {
                $this->streamHandler->dispatchError(
                    'api_error',
                    'Chat Completions API returned HTTP '.$response->status().': '.$response->body()
                );

                return;
            }

            $this->processStream($response);
        } catch (\Exception $e) {
            $this->streamHandler->dispatchError(
                'request_failed',
                'Chat Completions request failed: '.$e->getMessage()
            );
        }
    }

    public function cancel(): void
    {
        $this->cancelled = true;
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

                    // Dispatch any accumulated tool calls (only if not already dispatched)
                    if (! $toolCallsDispatched && ! empty($toolCallAccumulators)) {
                        foreach ($toolCallAccumulators as $accum) {
                            $params = json_decode($accum['arguments'], true) ?? [];
                            $this->streamHandler->dispatchToolCall(
                                $accum['name'],
                                $params,
                                $accum['id'],
                            );
                        }
                        $toolCallsDispatched = true;
                    }

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

                        // Dispatch accumulated tool calls (only if not already dispatched)
                        if (! $toolCallsDispatched && ! empty($toolCallAccumulators)) {
                            foreach ($toolCallAccumulators as $accum) {
                                $params = json_decode($accum['arguments'], true) ?? [];
                                $this->streamHandler->dispatchToolCall(
                                    $accum['name'],
                                    $params,
                                    $accum['id'],
                                );
                            }
                            $toolCallsDispatched = true;
                        }
                        $toolCallAccumulators = [];

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

                    // Dispatch any accumulated tool calls (only once)
                    if ($finishReason === 'tool_calls' && ! $toolCallsDispatched && ! empty($toolCallAccumulators)) {
                        foreach ($toolCallAccumulators as $accum) {
                            $params = json_decode($accum['arguments'], true) ?? [];
                            $this->streamHandler->dispatchToolCall(
                                $accum['name'],
                                $params,
                                $accum['id'],
                            );
                        }
                        $toolCallsDispatched = true;
                        $toolCallAccumulators = [];
                    }

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
            $this->streamHandler->dispatchError('cancelled', 'Stream was cancelled by the user.');
        } else {
            // Stream ended without [DONE] — likely a connection drop or upstream error.
            // Dispatch error so SSE/Reverb consumers don't hang indefinitely.
            $this->streamHandler->dispatchError(
                'stream_incomplete',
                'Chat Completions stream ended without a [DONE] sentinel. The connection may have dropped.'
            );
        }
    }
}
