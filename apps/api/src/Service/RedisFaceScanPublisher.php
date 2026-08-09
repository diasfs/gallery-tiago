<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Publishes scan jobs to `gallery:face-scan:stream` for the Python worker.
 */
final class RedisFaceScanPublisher implements FaceScanQueuePublisherInterface
{
    private const STREAM_KEY = 'gallery:face-scan:stream';

    private ?\Redis $redis = null;

    public function __construct(
        #[Autowire(env: 'REDIS_URL')]
        private readonly string $redisUrl,
    ) {
    }

    public function publish(string $scanId, string $photoId): void
    {
        $this->connection()->xAdd(self::STREAM_KEY, '*', [
            'scan_id' => $scanId,
            'photo_id' => $photoId,
        ]);
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
