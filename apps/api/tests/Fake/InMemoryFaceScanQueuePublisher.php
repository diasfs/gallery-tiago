<?php

namespace App\Tests\Fake;

use App\Service\FaceScanQueuePublisherInterface;

final class InMemoryFaceScanQueuePublisher implements FaceScanQueuePublisherInterface
{
    /** @var list<array{scanId: string, photoId: string}> */
    private array $published = [];

    public function publish(string $scanId, string $photoId): void
    {
        $this->published[] = ['scanId' => $scanId, 'photoId' => $photoId];
    }

    /** @return list<array{scanId: string, photoId: string}> */
    public function getPublished(): array
    {
        return $this->published;
    }

    public function reset(): void
    {
        $this->published = [];
    }
}
