<?php

namespace App\Service;

interface FaceScanQueuePublisherInterface
{
    public function publish(string $scanId, string $photoId): void;
}
