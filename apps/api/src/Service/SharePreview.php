<?php

namespace App\Service;

final readonly class SharePreview
{
    public function __construct(
        public string $title,
        public string $description,
        public string $canonicalUrl,
        public ?string $imageUrl,
        public ?string $imageType = null,
        public ?int $imageWidth = null,
        public ?int $imageHeight = null,
    ) {
    }
}
