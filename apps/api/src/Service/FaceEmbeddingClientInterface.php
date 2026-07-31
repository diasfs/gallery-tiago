<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;

interface FaceEmbeddingClientInterface
{
    /**
     * @return float[] InsightFace-normalized embedding for the largest detected face
     */
    public function embedUpload(UploadedFile $file): array;
}
