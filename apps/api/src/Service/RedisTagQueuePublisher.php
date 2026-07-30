<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Publishes photo ids to the Redis stream `gallery:tags:stream` consumed by
 * the Python tag-suggestion worker (apps/worker-tags).
 *
 * Uses Redis Streams (XADD) instead of a plain list so the worker can
 * acknowledge only after persisting a terminal tags_status. Abandoned
 * deliveries are reclaimable via XAUTOCLAIM.
 *
 * Bypasses Symfony Messenger's Redis transport the same way
 * RedisFaceQueuePublisher does.
 */
final class RedisTagQueuePublisher implements TagQueuePublisherInterface
{
    private const STREAM_KEY = 'gallery:tags:stream';

    private ?\Redis $redis = null;

    public function __construct(
        #[Autowire(env: 'REDIS_URL')]
        private readonly string $redisUrl,
    ) {
    }

    public function publish(string $photoId): void
    {
        $this->connection()->xAdd(self::STREAM_KEY, '*', ['photo_id' => $photoId]);
    }

    private function connection(): \Redis
    {
        if (null === $this->redis) {
            $parts = parse_url($this->redisUrl);
            if (false === $parts || !isset($parts['host'])) {
                throw new \RuntimeException(\sprintf('Invalid REDIS_URL "%s".', $this->redisUrl));
            }

            $redis = new \Redis();
            $redis->connect($parts['host'], $parts['port'] ?? 6379);

            $this->redis = $redis;
        }

        return $this->redis;
    }
}
