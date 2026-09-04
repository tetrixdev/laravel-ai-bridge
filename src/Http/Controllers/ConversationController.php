<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Tetrix\AiBridge\AiBridgeManager;
use Tetrix\AiBridge\Contracts\StreamStoreContract;
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
            'working_dir' => ['nullable', 'string', 'max:1024'],
        ]);

        // A supplied connection must be one the request is allowed to see.
        $connection = null;
        if (! empty($validated['connection_id'])) {
            $connection = $this->manager->connectionsQuery($request)
                ->whereKey($validated['connection_id'])->first();
            if ($connection === null) {
                return response()->json([
                    'error' => 'validation_error',
                    'message' => 'The selected connection is not available.',
                ], 422);
            }
        }

        // The workspace is chosen once, when the chat starts, because the
        // bridge fixes it for the life of the CLI session and refuses a later
        // turn that names a different one.
        if (! empty($validated['working_dir'])) {
            $rejection = $this->rejectUnknownWorkspace($validated['working_dir'], $connection);
            if ($rejection !== null) {
                return $rejection;
            }
        }

        $conversation = Conversation::create([
            'title' => $validated['title'] ?? null,
            'mode' => $validated['mode'],
            'provider' => $validated['provider'] ?? null,
            'model' => $validated['model'] ?? null,
            'system_prompt' => $validated['system_prompt'] ?? null,
            'connection_id' => $validated['connection_id'] ?? null,
            'working_dir' => $validated['working_dir'] ?? null,
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
            // The request_id of an actively-streaming turn for this
            // conversation, or null if none is in flight. The chat UI
            // checks this on conversation open to decide whether to attach
            // an EventSource to /ai-bridge/streams/{rid}/events for replay.
            'streaming_request_id' => $conversation->streaming_request_id,
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
     * Returns JSON {status, request_id} immediately. The browser then opens
     * an EventSource against /ai-bridge/streams/{request_id}/events to
     * receive the streamed reply; that endpoint resumes by Last-Event-ID
     * after a refresh or reconnect.
     */
    public function stream(Request $request, int|string $id): JsonResponse
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

        // One in-flight turn per conversation. A double-clicked Send (or a
        // second tab racing) would otherwise overwrite streaming_request_id
        // and leave one of the turns orphaned in the buffer. Reject with 409
        // when a turn is genuinely still running; ignore stale pointers
        // (a previous turn that died without clearing the column — buffer
        // says not_found / completed).
        if ($conversation->streaming_request_id !== null) {
            $status = app(StreamStoreContract::class)->status($conversation->streaming_request_id);
            if ($status['status'] === 'streaming') {
                return response()->json([
                    'error' => 'conflict',
                    'message' => 'Another turn is already in flight for this conversation.',
                    'request_id' => $conversation->streaming_request_id,
                ], 409);
            }
        }

        // Optional per-turn provider/model override (e.g. switching mid-conversation).
        $this->applyOverrides($request, $conversation);

        // A workspace may still be chosen on the FIRST message, for apps that
        // create the conversation before the developer has picked one. After
        // that it is fixed: silently switching it would produce a
        // `working_dir_changed` refusal from the bridge mid-chat, which is a
        // confusing way to learn that the directory is part of the session.
        if ($request->filled('working_dir')) {
            $requested = (string) $request->input('working_dir');
            if ($conversation->working_dir === null) {
                $conversation->loadMissing('connection');
                $rejection = $this->rejectUnknownWorkspace($requested, $conversation->connection);
                if ($rejection !== null) {
                    return $rejection;
                }
                $conversation->fill(['working_dir' => $requested])->save();
            } elseif ($conversation->working_dir !== $requested) {
                return response()->json([
                    'error' => 'validation_error',
                    'message' => 'This conversation is already working in "'.$conversation->working_dir
                        .'". A conversation cannot change working directory — start a new one.',
                ], 422);
            }
        }

        // Files attached to THIS message, as ids. Per turn rather than per
        // conversation: the bridge downloads them, tells the model where they
        // landed, and deletes them when the turn ends.
        //
        // Ids, not URLs — the server builds the URL from its own route, so a
        // browser cannot nominate what the bridge fetches. See
        // AiBridgeManager::buildAttachmentRefs().
        $options = [];
        $attachmentIds = $request->input('attachments');

        try {
            if (is_array($attachmentIds) && $attachmentIds !== []) {
                $conversation->loadMissing('connection');
                $options['attachments'] = $this->manager->buildAttachmentRefs(
                    $attachmentIds,
                    $conversation->connection?->connection_key ?? '',
                );
            }

            $requestId = $this->manager->startConversationStream($conversation, $message, $options);
        } catch (\InvalidArgumentException $e) {
            // An id that does not resolve, or an attachment missing a field the
            // bridge needs. A 422 naming the problem beats a turn that starts
            // and then fails somewhere the user cannot see.
            return response()->json([
                'error' => 'validation_error',
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'status' => 'started',
            'request_id' => $requestId,
        ]);
    }

    /**
     * Refuse a working directory the connected bridge has not advertised.
     *
     * The bridge is the authority — it refuses anything outside its own
     * `--allow-dir` roots whatever the server sends — so this is a courtesy,
     * not the control. But it is a worthwhile one: without it a mistyped path
     * is only discovered after the turn starts, arriving as a stream error in
     * the middle of a chat rather than as a validation failure on the request
     * that caused it.
     *
     * When the bridge advertised no workspaces the check is skipped rather
     * than failed: it may simply be between reconnects, and the bridge will
     * give the authoritative answer either way.
     */
    private function rejectUnknownWorkspace(string $workingDir, ?\Tetrix\AiBridge\Models\Connection $connection): ?JsonResponse
    {
        $workspaces = $connection?->last_workspaces ?? [];
        if ($workspaces === []) {
            return null;
        }

        foreach ($workspaces as $workspace) {
            $root = is_array($workspace) ? ($workspace['path'] ?? null) : null;
            if (! is_string($root) || $root === '') {
                continue;
            }
            if ($workingDir === $root || str_starts_with($workingDir, rtrim($root, '/').'/')) {
                return null;
            }
        }

        return response()->json([
            'error' => 'validation_error',
            'message' => 'That working directory is not one this bridge allows. '
                .'Choose one of the workspaces the connection advertises.',
        ], 422);
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
