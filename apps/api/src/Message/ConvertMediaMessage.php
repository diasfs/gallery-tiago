<?php

namespace App\Message;

final class ConvertMediaMessage
{
    public function __construct(
        private readonly string $photoId,
    ) {
    }

    public function getPhotoId(): string
    {
        return $this->photoId;
    }
}
