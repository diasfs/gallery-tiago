<?php

namespace App\Service;

/**
 * Enqueues a photo for face detection by the Python worker
 * (apps/worker-faces). Kept as an interface so tests can swap in an
 * in-memory fake instead of talking to real Redis (see when@test binding in
 * config/services.yaml and App\Tests\Fake\InMemoryFaceQueuePublisher).
 */
interface FaceQueuePublisherInterface
{
    public function publish(string $photoId): void;
}
