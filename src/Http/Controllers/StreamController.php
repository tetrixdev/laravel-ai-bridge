<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tetrix\AiBridge\AiBridgeManager;
use Tetrix\AiBridge\Protocol\StreamEvent;

/**
 * HTTP endpoints for streaming AI responses to the browser.
 *
 * Provides two delivery methods:
 * - SSE: Server-Sent Events streamed directly in the HTTP response.
 * - Broadcast: Events pushed to a Laravel Reverb channel for real-time delivery.
 */
class StreamController extends Controller
{
    public function __construct(
        private readonly AiBridgeManager $manager,
    ) {}

    /**
     * SSE streaming endpoint.
     *
     * POST /ai-bridge/stream/sse
     *
     * Accepts: conversation_id, message, system_prompt, options
     * Returns: text/event-stream response with normalized AI events.
     *
     * Each SSE line is: data: {"event": "...", "data": {...}}
     * Stream ends with: data: [DONE]
     */
    public function sse(Request $request): StreamedResponse
    {
        $conversationId = $request->input('conversation_id', 'conv-' . uniqid());
        $message = $request->input('message', '');
        $systemPrompt = $request->input('system_prompt', '');

        $options = $request->input('options', []);
        if (is_string($options)) {
            $options = json_decode($options, true) ?? [];
        }

        if (! empty($systemPrompt)) {
            $options['system_prompt'] = $systemPrompt;
        }

        // Allow per-request model override (safe: controls which model, not where requests go)
        if ($request->has('model')) {
            $options['model'] = $request->input('model');
        }

        // SEC: endpoint, api_key, and mode are NOT accepted from the request body.
        // These are server-side configuration only — accepting them from the client
        // would allow SSRF (endpoint), credential override (api_key), and mode switching.

        return $this->manager->streamToResponse($conversationId, $message, $options);
    }

    /**
     * Reverb broadcast endpoint.
     *
     * POST /ai-bridge/stream/broadcast
     *
     * Accepts: conversation_id, message, system_prompt, channel, options
     * Returns: JSON { "status": "started", "request_id": "..." }
     *
     * Events are broadcast to the specified Reverb channel as "ai.stream" events.
     */
    public function broadcast(Request $request): JsonResponse
    {
        $conversationId = $request->input('conversation_id', 'conv-' . uniqid());
        $message = $request->input('message', '');
        $systemPrompt = $request->input('system_prompt', '');
        // SEC: Channel name is derived server-side to prevent cross-user injection.
        // The client cannot choose which channel to broadcast on.
        $userId = $request->user()?->getAuthIdentifier() ?? 'anon';
        $channel = "private-user.{$userId}.conversation.{$conversationId}";

        $options = $request->input('options', []);
        if (is_string($options)) {
            $options = json_decode($options, true) ?? [];
        }

        if (! empty($systemPrompt)) {
            $options['system_prompt'] = $systemPrompt;
        }

        if ($request->has('model')) {
            $options['model'] = $request->input('model');
        }

        $requestId = $this->manager->streamAndBroadcast(
            $conversationId,
            $message,
            $channel,
            $options,
        );

        return response()->json([
            'status' => 'started',
            'request_id' => $requestId,
            'channel' => $channel,
        ]);
    }
}
