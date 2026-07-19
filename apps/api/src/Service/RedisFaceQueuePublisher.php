<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Pushes photo ids to the plain Redis list `gallery:faces` consumed by the
 * Python InsightFace worker (apps/worker-faces).
 *
 * This intentionally bypasses Symfony Messenger's own Redis transport
 * (config/packages/messenger.yaml `faces` transport): Messenger's Redis
 * transport uses stream entries with PHP-serialized envelopes, which a
 * non-PHP consumer cannot parse. Instead we RPUSH a minimal JSON payload
 * `{"photo_id":"<uuid>"}`, per the chosen contract in
 * docs/superpowers/specs/2026-07-19-photo-gallery-design.md §6 and
 * .superpowers/sdd/task-7-brief.md.
 */
final class RedisFaceQueuePublisher implements FaceQueuePublisherInterface
{
    private const QUEUE_KEY = 'gallery:faces';

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
