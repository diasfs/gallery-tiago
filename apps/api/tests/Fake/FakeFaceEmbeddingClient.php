<?php

namespace App\Tests\Fake;

use App\Service\FaceEmbeddingClientInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class FakeFaceEmbeddingClient implements FaceEmbeddingClientInterface
{
    /** @var float[]|null */
    public static ?array $nextEmbedding = null;

    public function embedUpload(UploadedFile $file): array
    {
        if (null !== self::$nextEmbedding) {
            return self::$nextEmbedding;
        }

        return array_fill(0, 512, 0.01);
    }

    public static function reset(): void
    {
        self::$nextEmbedding = null;
    }
}
