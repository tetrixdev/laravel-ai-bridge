<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Streaming;

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Manager;
use Tetrix\AiBridge\Contracts\StreamStoreContract;
use Tetrix\AiBridge\Streaming\Drivers\ArrayStreamStore;
use Tetrix\AiBridge\Streaming\Drivers\RedisStreamStore;

/**
 * Driver-based factory for the stream event buffer.
 *
 * Mirrors Laravel's cache/queue/filesystem manager pattern: a driver name in
 * config picks the implementation, and consuming applications register their
 * own drivers via {@see extend()}. The default is configured in
 * `ai-bridge.stream_store.default`.
 *
 * @method StreamStoreContract driver(?string $driver = null)
 */
class StreamStoreManager extends Manager
{
    /**
     * Build the default Redis driver from package config.
     */
    public function createRedisDriver(): StreamStoreContract
    {
        $config = (array) $this->config->get('ai-bridge.stream_store.redis', []);

        return new RedisStreamStore(
            $this->container->make(RedisFactory::class),
            connectionName: $config['connection'] ?? null,
            prefix: (string) ($config['prefix'] ?? 'ai-bridge:stream'),
            streamingTtl: (int) ($config['ttl_streaming'] ?? 3600),
            completedTtl: (int) ($config['ttl_completed'] ?? 1800),
        );
    }

    /**
     * Build the in-memory array driver — intended for tests.
     */
    public function createArrayDriver(): StreamStoreContract
    {
        return new ArrayStreamStore();
    }

    /**
     * The default driver name. Reads from `ai-bridge.stream_store.default`,
     * falling back to the legacy package-global default of `redis`.
     */
    public function getDefaultDriver(): string
    {
        return (string) $this->config->get('ai-bridge.stream_store.default', 'redis');
    }
}
