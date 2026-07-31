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

        $statusCode = $response->getStatusCode();
        if ($statusCode >= 400) {
            $detail = 'Face embedding request failed.';
            try {
                $payload = $response->toArray(false);
                if (\is_string($payload['error'] ?? null) && '' !== $payload['error']) {
                    $detail = $payload['error'];
                }
            } catch (\Throwable) {
                // keep generic message
            }

            throw new \RuntimeException($detail);
        }

        $payload = $response->toArray(false);
        $embedding = $payload['embedding'] ?? null;
        if (!\is_array($embedding) || [] === $embedding) {
            throw new \RuntimeException('No face detected in the uploaded image.');
        }

        return array_map('floatval', $embedding);
    }
}
