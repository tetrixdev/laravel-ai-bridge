<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tetrix\AiBridge\AiBridgeManager;
use Tetrix\AiBridge\Contracts\StreamStoreContract;
use Tetrix\AiBridge\Models\Conversation;

/**
 * Per-turn streaming endpoints — status, SSE event tail, abort.
 *
 * The browser doesn't talk to Reverb anymore: it opens a native EventSource
 * against {@see events()}, which is a PHP-FPM long-poll that drains the
 * per-turn buffer ({@see StreamStoreContract}) and tails it for new entries.
 * Resumption is free — `EventSource` sends `Last-Event-ID` on reconnect, the
 * server resumes from the next index.
 *
 * Authorization for every endpoint here goes through the conversations
 * resolver registered by the consuming app, the same way
 * {@see ConversationController} does. The stream-store metadata carries
 * `conversation_id` so we can authorize by conversation rather than by
 * request_id directly.
 */
class StreamEventsController extends Controller
{
    public function __construct(
        private readonly AiBridgeManager $manager,
        private readonly StreamStoreContract $store,
    ) {}

    /**
     * GET /ai-bridge/streams/{requestId}/status
     *
     * Lets the browser decide on page load whether to attach an EventSource:
     * if status is "streaming", do it; otherwise the DB already has the row
     * and no replay is needed.
     */
    public function status(Request $request, string $requestId): JsonResponse
    {
        $status = $this->store->status($requestId);
        if ($status['status'] === 'not_found') {
            return response()->json(['error' => 'not_found'], 404);
        }

        if (! $this->canAccess($request, $status['metadata'])) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        return response()->json([
            'status' => $status['status'],
            'event_count' => $status['event_count'],
            'last_event_index' => $status['last_event_index'],
            'metadata' => $status['metadata'],
        ]);
    }

    /**
     * GET /ai-bridge/streams/{requestId}/events
     *
     * SSE long-poll of the per-turn event buffer. Each event line carries
     * the buffer-assigned monotonic `id:` so `EventSource` can resume by
     * `Last-Event-ID` after a reconnect.
     */
    public function events(Request $request, string $requestId): StreamedResponse|JsonResponse
    {
        $status = $this->store->status($requestId);
        if ($status['status'] === 'not_found') {
            return response()->json(['error' => 'not_found'], 404);
        }

        if (! $this->canAccess($request, $status['metadata'])) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        $fromIndex = self::resolveFromIndex($request);

        $pollIntervalMs = (int) config('ai-bridge.stream_store.poll_interval_ms', 100);
        $keepaliveS = (int) config('ai-bridge.stream_store.keepalive_interval_s', 30);
        $maxConnS = (int) config('ai-bridge.stream_store.max_connection_s', 600);

        $store = $this->store;

        return new StreamedResponse(function () use ($store, $requestId, $fromIndex, $pollIntervalMs, $keepaliveS, $maxConnS): void {
            // Disable output buffering wherever we can so events leave the
            // process as fast as they're written. The X-Accel-Buffering header
            // tells nginx to do the same upstream.
            @ini_set('zlib.output_compression', '0');
            while (ob_get_level() > 0) {
                @ob_end_flush();
            }

            $lastIndex = $fromIndex;
            $startedAt = microtime(true);
            $lastKeepalive = $startedAt;
            $pollIntervalUs = max(10, $pollIntervalMs) * 1000;

            while (true) {
                if (connection_aborted()) {
                    return;
                }
                if ((microtime(true) - $startedAt) > $maxConnS) {
                    // Cap a single connection's lifetime — the browser will
                    // EventSource-reconnect from Last-Event-ID for free.
                    return;
                }

                $events = $store->range($requestId, $lastIndex);
                foreach ($events as $event) {
                    $lastIndex = (int) $event['index'];
                    self::emit($event['index'], $event['event'], $event['data']);
                    if (connection_aborted()) {
                        return;
                    }
                }

                $status = $store->status($requestId);
                if ($status['status'] !== 'streaming') {
                    // Drain any straggler events written between the last
                    // range() and the status flip.
                    $tail = $store->range($requestId, $lastIndex);
                    foreach ($tail as $event) {
                        $lastIndex = (int) $event['index'];
                        self::emit($event['index'], $event['event'], $event['data']);
                    }

                    return;
                }

                $now = microtime(true);
                if (($now - $lastKeepalive) >= $keepaliveS) {
                    echo ": keepalive\n\n";
                    @ob_flush();
                    @flush();
                    $lastKeepalive = $now;
                }

                usleep($pollIntervalUs);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * POST /ai-bridge/streams/{requestId}/abort
     *
     * Flag this turn for cancellation. The serve process polls the flag
     * before dispatching each event from the bridge.
     */
    public function abort(Request $request, string $requestId): JsonResponse
    {
        $status = $this->store->status($requestId);
        if ($status['status'] === 'not_found') {
            return response()->json(['error' => 'not_found'], 404);
        }

        if (! $this->canAccess($request, $status['metadata'])) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        $this->store->setAbort($requestId);

        return response()->json(['status' => 'abort_requested', 'request_id' => $requestId]);
    }

    /**
     * Whether the current request is allowed to see this turn.
     *
     * The turn's metadata records the conversation id; we re-use the
     * conversations resolver to authorize, so the same scope that gates the
     * conversation gates its in-flight stream.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function canAccess(Request $request, array $metadata): bool
    {
        $conversationId = $metadata['conversation_id'] ?? null;
        if ($conversationId === null || $conversationId === '') {
            // A turn with no associated conversation is server-only and
            // should never be reachable from the browser endpoints.
            return false;
        }

        return $this->manager->conversationsQuery($request)
            ->whereKey($conversationId)
            ->exists();
    }

    /**
     * Resolve the resume-from index from request headers/query.
     *
     * The SSE-spec `Last-Event-ID` header wins (the browser sets it
     * automatically on reconnect); the `from_index` query param is the
     * manual fallback for clients that can't influence headers. Non-numeric
     * / empty values fall back to -1 (replay from the start) — never to 0,
     * which would silently skip event 0.
     *
     * @internal Exposed for testability; not part of the public API.
     */
    public static function resolveFromIndex(Request $request): int
    {
        $lastEventId = $request->header('Last-Event-ID');
        if ($lastEventId !== null && is_numeric($lastEventId)) {
            return (int) $lastEventId;
        }

        $raw = $request->query('from_index');
        if ($raw !== null && $raw !== '' && is_numeric($raw)) {
            return (int) $raw;
        }

        return -1;
    }

    /**
     * Emit one SSE event with id/event/data lines and flush.
     *
     * @param  array<string, mixed>  $data
     */
    private static function emit(int $index, string $event, array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        echo "id: {$index}\n";
        echo "event: {$event}\n";
        echo "data: {$json}\n\n";
        @ob_flush();
        @flush();
    }
}
