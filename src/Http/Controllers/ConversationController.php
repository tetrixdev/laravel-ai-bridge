<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tetrix\AiBridge\AiBridgeManager;
use Tetrix\AiBridge\Enums\ProviderMode;
use Tetrix\AiBridge\Events\ConversationCreated;
use Tetrix\AiBridge\Models\Conversation;
use Tetrix\AiBridge\Tools\ToolRegistry;

/**
 * HTTP API for conversation CRUD + streaming.
 *
 * Every action is scoped through the project-supplied conversations resolver
 * (AiBridgeManager::conversationsQuery) — the package itself owns no ownership
 * concept. Routes are additionally protected by the configured route_middleware.
 */
class ConversationController extends Controller
{
    public function __construct(
        private readonly AiBridgeManager $manager,
        private readonly ToolRegistry $toolRegistry,
    ) {}

    /**
     * GET /ai-bridge/conversations — list conversations visible to the request.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->integer('per_page', 30), 1), 100);

        $conversations = $this->manager->conversationsQuery($request)
            ->latest('updated_at')
            ->paginate($perPage);

        return response()->json($conversations);
    }

    /**
     * POST /ai-bridge/conversations — create a conversation.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'mode' => ['required', 'string', 'in:bridge,byok,managed'],
            'provider' => ['nullable', 'string', 'max:64'],
            'model' => ['nullable', 'string', 'max:128'],
            'system_prompt' => ['nullable', 'string', 'max:'.(int) config('ai-bridge.streaming.max_system_prompt_length', 10000)],
            'connection_id' => ['nullable', 'integer'],
        ]);

        // A supplied connection must be one the request is allowed to see.
        if (! empty($validated['connection_id'])) {
            $owns = $this->manager->connectionsQuery($request)
                ->whereKey($validated['connection_id'])->exists();
            if (! $owns) {
                return response()->json([
                    'error' => 'validation_error',
                    'message' => 'The selected connection is not available.',
                ], 422);
            }
        }

        $conversation = Conversation::create([
            'title' => $validated['title'] ?? null,
            'mode' => $validated['mode'],
            'provider' => $validated['provider'] ?? null,
            'model' => $validated['model'] ?? null,
            'system_prompt' => $validated['system_prompt'] ?? null,
            'connection_id' => $validated['connection_id'] ?? null,
            'tools_hash' => $this->toolRegistry->hash(),
        ]);

        // Let the consuming app link the (unlinked) conversation to its owner.
        event(new ConversationCreated($conversation, $request));

        return response()->json($conversation->fresh(), 201);
    }

    /**
     * GET /ai-bridge/conversations/{id} — a conversation with its messages.
     */
    public function show(Request $request, int|string $id): JsonResponse
    {
        $conversation = $this->manager->conversationsQuery($request)
            ->whereKey($id)->first();

        if ($conversation === null) {
            return response()->json(['error' => 'not_found', 'message' => 'Conversation not found.'], 404);
        }

        $conversation->load('messages', 'connection');

        return response()->json([
            'conversation' => $conversation,
            'messages' => $conversation->messages,
            // The broadcast channel for this conversation's stream events. The
            // chat UI subscribes to it as soon as a conversation is opened —
            // before any message is sent — so a fast terminal event (e.g. an
            // immediate error) cannot be broadcast before the browser is
            // listening. Reverb does not replay missed events.
            'channel' => $conversation->broadcastChannel(),
            // Soft signal: the registered tool set changed since this
            // conversation was created.
            'tools_stale' => $conversation->tools_hash !== null
                && $conversation->tools_hash !== $this->toolRegistry->hash(),
        ]);
    }

    /**
     * DELETE /ai-bridge/conversations/{id}.
     */
    public function destroy(Request $request, int|string $id): JsonResponse
    {
        $conversation = $this->manager->conversationsQuery($request)
            ->whereKey($id)->first();

        if ($conversation === null) {
            return response()->json(['error' => 'not_found', 'message' => 'Conversation not found.'], 404);
        }

        $conversation->delete(); // messages cascade via FK

        return response()->json(['status' => 'deleted']);
    }

    /**
     * POST /ai-bridge/conversations/{id}/stream — send a message.
     *
     * Bridge mode → starts a Reverb broadcast, returns JSON {request_id, channel}.
     * BYOK / Managed → returns an SSE stream.
     */
    public function stream(Request $request, int|string $id): StreamedResponse|JsonResponse
    {
        $conversation = $this->manager->conversationsQuery($request)
            ->whereKey($id)->first();

        if ($conversation === null) {
            return response()->json(['error' => 'not_found', 'message' => 'Conversation not found.'], 404);
        }

        $message = (string) $request->input('message', '');
        if (trim($message) === '') {
            return response()->json([
                'error' => 'validation_error',
                'message' => 'The message field is required and cannot be empty.',
            ], 422);
        }

        // Optional per-turn provider/model override (e.g. switching mid-conversation).
        $this->applyOverrides($request, $conversation);

        if (ProviderMode::from($conversation->mode) === ProviderMode::Bridge) {
            $requestId = $this->manager->streamConversationAndBroadcast($conversation, $message);

            return response()->json([
                'status' => 'started',
                'request_id' => $requestId,
                'channel' => $conversation->broadcastChannel(),
            ]);
        }

        return $this->manager->streamConversationToResponse($conversation, $message);
    }

    /**
     * Apply an optional per-turn provider/model switch to the conversation.
     */
    private function applyOverrides(Request $request, Conversation $conversation): void
    {
        $dirty = [];

        if ($request->filled('provider') && $request->input('provider') !== $conversation->provider) {
            $dirty['provider'] = (string) $request->input('provider');
        }
        if ($request->filled('model') && $request->input('model') !== $conversation->model) {
            $dirty['model'] = (string) $request->input('model');
        }

        if ($dirty !== []) {
            $conversation->fill($dirty)->save();
        }
    }
}
