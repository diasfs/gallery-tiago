<?php

namespace App\Tests\Fake;

use App\Service\ViewDeduplicatorInterface;

final class InMemoryViewDeduplicator implements ViewDeduplicatorInterface
{
    /** @var array<string, true> */
    private static array $claims = [];

    public function claim(string $resourceType, string $resourceId, string $visitorId): bool
    {
        $key = $resourceType.':'.$resourceId.':'.$visitorId;
        if (isset(self::$claims[$key])) {
            return false;
        }

        self::$claims[$key] = true;

        return true;
    }

    public function reset(): void
    {
        self::$claims = [];
    }
}
