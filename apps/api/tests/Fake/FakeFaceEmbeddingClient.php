<?php

namespace App\Tests\Fake;

use App\Service\FaceEmbeddingClientInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class FakeFaceEmbeddingClient implements FaceEmbeddingClientInterface
{
    /** @var float[]|null */
    public static ?array $nextEmbedding = null;

    /** @var list<array{embedding: float[], x: float, y: float, width: float, height: float, cropJpeg: string}>|null */
    public static ?array $nextDetections = null;

    public function embedUpload(UploadedFile $file): array
    {
        if (null !== self::$nextEmbedding) {
            return self::$nextEmbedding;
        }

        return array_fill(0, 512, 0.01);
    }

    public function detectUpload(UploadedFile $file): array
    {
        if (null !== self::$nextDetections) {
            return self::$nextDetections;
        }

        return [[
            'embedding' => array_fill(0, 512, 0.01),
            'x' => 0.0,
            'y' => 0.0,
            'width' => 10.0,
            'height' => 10.0,
            'cropJpeg' => "\xff\xd8fake",
        ]];
    }

    public static function reset(): void
    {
        self::$nextEmbedding = null;
        self::$nextDetections = null;
    }
}
