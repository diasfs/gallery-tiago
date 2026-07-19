<?php

namespace App\Tests\Fake;

use App\Service\FaceQueuePublisherInterface;

/**
 * Test double bound in place of RedisFaceQueuePublisher (see when@test in
 * config/services.yaml) so handler tests can assert on published photo ids
 * without a live Redis connection.
 */
final class InMemoryFaceQueuePublisher implements FaceQueuePublisherInterface
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
