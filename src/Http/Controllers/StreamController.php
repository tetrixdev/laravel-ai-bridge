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
    public function sse(Request $request): StreamedResponse|JsonResponse
    {
        $prepared = $this->prepareRequestOptions($request);
        if ($prepared instanceof JsonResponse) {
            return $prepared;
        }

        [$conversationId, $message, $options] = $prepared;

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

        $prepared = $this->prepareRequestOptions($request);
        if ($prepared instanceof JsonResponse) {
            return $prepared;
        }

        [$conversationId, $message, $options] = $prepared;

        // SEC: Channel name is derived server-side to prevent cross-user injection.
        // The client cannot choose which channel to broadcast on.
        // Note: PrivateChannel prepends "private-" automatically, so pass without prefix.
        $channel = "user.{$userId}.conversation.{$conversationId}";

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
     * Validate and prepare request options shared between sse() and broadcast().
     *
     * Returns either a [conversationId, message, options] array on success,
     * or a JsonResponse on validation failure.
     *
     * @return array{string, string, array<string, mixed>}|JsonResponse
     */
    private function prepareRequestOptions(Request $request): array|JsonResponse
    {
        $message = $request->input('message', '');
        if (empty(trim($message))) {
            return response()->json([
                'error' => 'validation_error',
                'message' => 'The message field is required and cannot be empty.',
            ], 422);
        }

        $conversationId = $request->input('conversation_id', 'conv-' . uniqid());

        $mode = config('ai-bridge.mode');
        $isManagedMode = $mode === 'managed';
        $options = $this->sanitizeOptions($request);

        // Only allow per-request overrides in non-managed mode.
        // In managed mode, the app controls AI behavior and cost.
        if (! $isManagedMode) {
            $systemPrompt = $request->input('system_prompt', '');
            if (! empty($systemPrompt)) {
                $options['system_prompt'] = $systemPrompt;
            }

            if ($request->has('model')) {
                $options['model'] = $request->input('model');
            }
        }

        return [$conversationId, $message, $options];
    }

    /**
     * Parse and sanitize the options from the request.
     *
     * Strips fields that must not be controlled by the client:
     * - endpoint, api_key: Would allow SSRF / credential override.
     * - mode: Would allow switching provider mode.
     * - user_id: Must come from auth, not client input.
     *
     * In managed mode, also strips cost/behavior fields (model, max_tokens,
     * system_prompt, messages) since the application controls AI behavior.
     *
     * @return array<string, mixed>
     */
    private function sanitizeOptions(Request $request): array
    {
        $options = $request->input('options', []);
        if (is_string($options)) {
            $options = json_decode($options, true) ?? [];
        }

        // Always strip security-sensitive fields
        unset($options['endpoint'], $options['api_key'], $options['mode'], $options['user_id']);

        // In managed mode, also strip fields that control cost and AI behavior.
        // The application bears the cost, so clients must not override model, tokens, etc.
        if (config('ai-bridge.mode') === 'managed') {
            unset($options['model'], $options['max_tokens'], $options['system_prompt'], $options['messages']);
        }

        return $options;
    }
}
