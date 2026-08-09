<?php

namespace App\Message;

final class ScanFaceMessage
{
    public function __construct(
        private readonly string $scanId,
        private readonly string $photoId,
    ) {
    }

    public function getScanId(): string
    {
        return $this->scanId;
    }

    public function getPhotoId(): string
    {
        return $this->photoId;
    }
}
