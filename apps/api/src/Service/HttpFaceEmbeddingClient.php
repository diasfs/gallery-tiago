<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
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
        $payload = $this->postMultipart($file, '/embed');
        $embedding = $payload['embedding'] ?? null;
        if (!\is_array($embedding) || [] === $embedding) {
            throw new \RuntimeException('No face detected in the uploaded image.');
        }

        return array_map('floatval', $embedding);
    }

    public function detectUpload(UploadedFile $file): array
    {
        $payload = $this->postMultipart($file, '/detect');
        $faces = $payload['faces'] ?? null;
        if (!\is_array($faces) || [] === $faces) {
            throw new \RuntimeException('No face detected in the uploaded image.');
        }

        $detected = [];
        foreach ($faces as $face) {
            if (!\is_array($face)) {
                continue;
            }
            $embedding = $face['embedding'] ?? null;
            $crop = $face['cropJpeg'] ?? null;
            if (!\is_array($embedding) || !\is_string($crop) || '' === $crop) {
                continue;
            }
            $jpeg = base64_decode($crop, true);
            if (false === $jpeg || '' === $jpeg) {
                continue;
            }
            $detected[] = [
                'embedding' => array_map('floatval', $embedding),
                'x' => (float) ($face['x'] ?? 0),
                'y' => (float) ($face['y'] ?? 0),
                'width' => (float) ($face['width'] ?? 0),
                'height' => (float) ($face['height'] ?? 0),
                'cropJpeg' => $jpeg,
            ];
        }
        if ([] === $detected) {
            throw new \RuntimeException('No face detected in the uploaded image.');
        }

        return $detected;
    }

    /** @return array<string, mixed> */
    private function postMultipart(UploadedFile $file, string $path): array
    {
        if (null === $this->embedUrl || '' === trim($this->embedUrl)) {
            throw new ServiceUnavailableHttpException('Face embedding service is not configured.');
        }

        // FormDataPart (not fopen-in-array): Symfony's profiler HttpClientDataCollector
        // calls explode() on normalizeBody() output; array+resource becomes a Closure and fatals in dev.
        $formData = new FormDataPart([
            'file' => DataPart::fromPath(
                $file->getPathname(),
                $file->getClientOriginalName() ?: 'upload.jpg',
                $file->getMimeType() ?: 'application/octet-stream',
            ),
        ]);

        $response = $this->httpClient->request('POST', rtrim($this->embedUrl, '/').$path, [
            'headers' => $formData->getPreparedHeaders()->toArray(),
            'body' => $formData->bodyToString(),
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

        return $response->toArray(false);
    }
}
