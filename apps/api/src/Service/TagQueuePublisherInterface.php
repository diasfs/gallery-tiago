<?php

namespace App\Service;

/**
 * Enqueues a photo for automatic tag suggestion by the Python worker
 * (apps/worker-tags). Kept as an interface so tests can swap in an
 * in-memory fake instead of talking to real Redis (see when@test binding in
 * config/services.yaml and App\Tests\Fake\InMemoryTagQueuePublisher).
 */
interface TagQueuePublisherInterface
{
    public function publish(string $photoId): void;
}
