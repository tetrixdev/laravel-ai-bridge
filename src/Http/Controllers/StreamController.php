<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tetrix\AiBridge\AiBridgeManager;

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

        $options = $this->sanitizeOptions($request);

        if (! empty($systemPrompt)) {
            $options['system_prompt'] = $systemPrompt;
        }

        // Allow per-request model override (safe: controls which model, not where requests go)
        if ($request->has('model')) {
            $options['model'] = $request->input('model');
        }

        return $this->manager->streamToResponse($conversationId, $message, $options);
    }

    /**
     * Reverb broadcast endpoint.
     *
     * POST /ai-bridge/stream/broadcast
     *
     * Accepts: conversation_id, message, system_prompt, options
     * Returns: JSON { "status": "started", "request_id": "..." }
     *
     * Events are broadcast to a private Reverb channel derived server-side
     * from the authenticated user. Clients listen via Laravel Echo.
     */
    public function broadcast(Request $request): JsonResponse
    {
        // SEC: Reject unauthenticated requests — no 'anon' fallback.
        // Broadcasting to a channel without a real user ID would bypass authorization.
        $userId = $request->user()?->getAuthIdentifier();
        if ($userId === null) {
            return response()->json([
                'error' => 'unauthenticated',
                'message' => 'Authentication required for broadcast streaming.',
            ], 401);
        }

        $conversationId = $request->input('conversation_id', 'conv-' . uniqid());
        $message = $request->input('message', '');
        $systemPrompt = $request->input('system_prompt', '');

        // SEC: Channel name is derived server-side to prevent cross-user injection.
        // The client cannot choose which channel to broadcast on.
        // Note: PrivateChannel prepends "private-" automatically, so pass without prefix.
        $channel = "user.{$userId}.conversation.{$conversationId}";

        $options = $this->sanitizeOptions($request);

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

    /**
     * Parse and sanitize the options from the request.
     *
     * Strips fields that must not be controlled by the client:
     * - endpoint, api_key: Would allow SSRF / credential override.
     * - mode: Would allow switching provider mode.
     * - user_id: Must come from auth, not client input.
     *
     * @return array<string, mixed>
     */
    private function sanitizeOptions(Request $request): array
    {
        $options = $request->input('options', []);
        if (is_string($options)) {
            $options = json_decode($options, true) ?? [];
        }

        unset($options['endpoint'], $options['api_key'], $options['mode'], $options['user_id']);

        return $options;
    }
}
