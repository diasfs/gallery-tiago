<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class RedisViewDeduplicator implements ViewDeduplicatorInterface
{
    private const TTL_SECONDS = 86400;

    private ?\Redis $redis = null;

    public function __construct(
        #[Autowire(env: 'REDIS_URL')]
        private readonly string $redisUrl,
    ) {
    }

    public function claim(string $resourceType, string $resourceId, string $visitorId): bool
    {
        $visitorHash = hash('sha256', $visitorId);
        $key = \sprintf('gallery:view:%s:%s:%s', $resourceType, $resourceId, $visitorHash);

        return true === $this->connection()->set($key, '1', ['nx', 'ex' => self::TTL_SECONDS]);
    }

    private function connection(): \Redis
    {
        if (null !== $this->redis) {
            return $this->redis;
        }

        $parts = parse_url($this->redisUrl);
        if (false === $parts || !isset($parts['host'])) {
            throw new \RuntimeException(\sprintf('Invalid REDIS_URL "%s".', $this->redisUrl));
        }

        $redis = new \Redis();
        $redis->connect($parts['host'], $parts['port'] ?? 6379);
        if (isset($parts['pass'])) {
            $redis->auth($parts['pass']);
        }

        $database = isset($parts['path']) ? (int) ltrim($parts['path'], '/') : 0;
        if ($database > 0) {
            $redis->select($database);
        }

        return $this->redis = $redis;
    }
}
