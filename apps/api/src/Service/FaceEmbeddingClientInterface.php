<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;

interface FaceEmbeddingClientInterface
{
    /**
     * @return float[] InsightFace-normalized embedding for the largest detected face
     */
    public function embedUpload(UploadedFile $file): array;

    /**
     * @return list<array{embedding: float[], x: float, y: float, width: float, height: float, cropJpeg: string}>
     */
    public function detectUpload(UploadedFile $file): array;
}
