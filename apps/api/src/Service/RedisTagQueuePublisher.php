<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Pushes photo ids to the plain Redis list `gallery:tags` consumed by the
 * Python RAM tagging worker (apps/worker-tags).
 *
 * Bypasses Symfony Messenger's Redis transport the same way
 * RedisFaceQueuePublisher does: Messenger stream envelopes are PHP-serialized
 * and unusable by a non-PHP consumer. We RPUSH `{"photo_id":"<uuid>"}` instead.
 */
final class RedisTagQueuePublisher implements TagQueuePublisherInterface
{
    private const QUEUE_KEY = 'gallery:tags';

    private ?\Redis $redis = null;

    public function __construct(
        #[Autowire(env: 'REDIS_URL')]
        private readonly string $redisUrl,
    ) {
    }

    public function publish(string $photoId): void
    {
        $payload = json_encode(['photo_id' => $photoId], JSON_THROW_ON_ERROR);
        $this->connection()->rPush(self::QUEUE_KEY, $payload);
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
