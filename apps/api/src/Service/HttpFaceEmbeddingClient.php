<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class HttpFaceEmbeddingClient implements FaceEmbeddingClientInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ?string $embedUrl,
    ) {
    }

    public function embedUpload(UploadedFile $file): array
    {
        if (null === $this->embedUrl || '' === trim($this->embedUrl)) {
            throw new ServiceUnavailableHttpException('Face embedding service is not configured.');
        }

        $response = $this->httpClient->request('POST', rtrim($this->embedUrl, '/').'/embed', [
            'body' => [
                'file' => fopen($file->getPathname(), 'r'),
            ],
        ]);

        if ($response->getStatusCode() >= 400) {
            throw new ServiceUnavailableHttpException('Face embedding request failed.');
        }

        $payload = $response->toArray(false);
        $embedding = $payload['embedding'] ?? null;
        if (!\is_array($embedding) || [] === $embedding) {
            throw new \RuntimeException('No face detected in the uploaded image.');
        }

        return array_map('floatval', $embedding);
    }
}
