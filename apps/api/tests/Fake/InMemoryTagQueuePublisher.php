<?php

namespace App\Tests\Fake;

use App\Service\TagQueuePublisherInterface;

/**
 * Test double bound in place of RedisTagQueuePublisher (see when@test in
 * config/services.yaml) so handler tests can assert on published photo ids
 * without a live Redis connection.
 */
final class InMemoryTagQueuePublisher implements TagQueuePublisherInterface
{
    /** @var string[] */
    private array $published = [];

    public function publish(string $photoId): void
    {
        $this->published[] = $photoId;
    }

    /** @return string[] */
    public function getPublished(): array
    {
        return $this->published;
    }
}
