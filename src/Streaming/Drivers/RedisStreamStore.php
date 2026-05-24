<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Streaming\Drivers;

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection;
use Tetrix\AiBridge\Contracts\StreamStoreContract;

/**
 * Redis-backed stream store.
 *
 * Keys (under the configured prefix):
 *   {prefix}:{rid}:events    — Redis list of JSON-encoded {index,event,data}
 *   {prefix}:{rid}:meta      — JSON metadata (conversation_id, started_at, ...)
 *   {prefix}:{rid}:status    — string: streaming|completed|failed|cancelled
 *   {prefix}:{rid}:abort     — exists ⇒ aborted
 *
 * TTLs:
 *   While status=streaming, each touch resets TTL to $streamingTtl.
 *   On complete(), TTL is shortened to $completedTtl so a recent reload can
 *   still replay but the entry doesn't linger.
 */
final class RedisStreamStore implements StreamStoreContract
{
    public function __construct(
        private readonly RedisFactory $redis,
        private readonly ?string $connectionName,
        private readonly string $prefix,
        private readonly int $streamingTtl,
        private readonly int $completedTtl,
    ) {}

    public function start(string $requestId, array $metadata = []): void
    {
        $conn = $this->conn();

        // SETNX on status — first writer wins, keeps the existing log if
        // start() is called twice for the same request_id.
        $created = (bool) $conn->setnx($this->key($requestId, 'status'), 'streaming');
        if ($created) {
            $conn->expire($this->key($requestId, 'status'), $this->streamingTtl);
        }

        $conn->set(
            $this->key($requestId, 'meta'),
            json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'EX',
            $this->streamingTtl,
        );
    }

    public function appendEvent(string $requestId, string $eventName, array $data): int
    {
        $conn = $this->conn();
        $eventsKey = $this->key($requestId, 'events');

        // RPUSH returns the new list length; subtract 1 for the appended
        // element's index. The JSON carries the index too, so range() never
        // has to recompute it.
        $newLength = (int) $conn->rpush(
            $eventsKey,
            json_encode([
                'index' => null, // placeholder; we fix it after the push
                'event' => $eventName,
                'data' => $data,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );

        $index = $newLength - 1;

        // Re-encode with the assigned index in place. LSET overwrites the
        // just-pushed element atomically with respect to other writers.
        $conn->lset(
            $eventsKey,
            $index,
            json_encode([
                'index' => $index,
                'event' => $eventName,
                'data' => $data,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );

        $conn->expire($eventsKey, $this->streamingTtl);

        return $index;
    }

    public function range(string $requestId, int $fromIndex = -1): array
    {
        $conn = $this->conn();
        $eventsKey = $this->key($requestId, 'events');

        $start = $fromIndex < 0 ? 0 : $fromIndex + 1;
        $raw = $conn->lrange($eventsKey, $start, -1);

        $out = [];
        foreach ($raw as $line) {
            $decoded = json_decode((string) $line, true);
            if (is_array($decoded) && isset($decoded['event'])) {
                $out[] = [
                    'index' => (int) ($decoded['index'] ?? 0),
                    'event' => (string) $decoded['event'],
                    'data' => is_array($decoded['data'] ?? null) ? $decoded['data'] : [],
                ];
            }
        }

        return $out;
    }

    public function status(string $requestId): array
    {
        $conn = $this->conn();

        $status = $conn->get($this->key($requestId, 'status'));
        if ($status === null) {
            return [
                'status' => 'not_found',
                'event_count' => 0,
                'last_event_index' => -1,
                'metadata' => [],
            ];
        }

        $count = (int) $conn->llen($this->key($requestId, 'events'));
        $rawMeta = $conn->get($this->key($requestId, 'meta'));
        $meta = $rawMeta === null ? [] : (json_decode((string) $rawMeta, true) ?: []);

        return [
            'status' => (string) $status,
            'event_count' => $count,
            'last_event_index' => $count === 0 ? -1 : $count - 1,
            'metadata' => is_array($meta) ? $meta : [],
        ];
    }

    public function setAbort(string $requestId): void
    {
        $conn = $this->conn();
        $conn->set($this->key($requestId, 'abort'), '1', 'EX', $this->streamingTtl);
    }

    public function isAborted(string $requestId): bool
    {
        return (bool) $this->conn()->exists($this->key($requestId, 'abort'));
    }

    public function complete(string $requestId, string $status): void
    {
        $conn = $this->conn();
        $conn->set($this->key($requestId, 'status'), $status, 'EX', $this->completedTtl);
        $conn->expire($this->key($requestId, 'events'), $this->completedTtl);
        $conn->expire($this->key($requestId, 'meta'), $this->completedTtl);
    }

    public function cleanup(string $requestId): void
    {
        $conn = $this->conn();
        $conn->del(
            $this->key($requestId, 'events'),
            $this->key($requestId, 'meta'),
            $this->key($requestId, 'status'),
            $this->key($requestId, 'abort'),
        );
    }

    private function conn(): Connection
    {
        return $this->redis->connection($this->connectionName);
    }

    private function key(string $requestId, string $suffix): string
    {
        return $this->prefix.':'.$requestId.':'.$suffix;
    }
}
