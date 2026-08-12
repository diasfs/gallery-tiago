<?php

namespace App\Tests\Service;

use App\Service\HttpFaceEmbeddingClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class HttpFaceEmbeddingClientTest extends TestCase
{
    public function testEmbedUploadSendsStringMultipartBody(): void
    {
        $embedding = array_fill(0, 8, 0.5);
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($embedding): MockResponse {
            $this->assertSame('POST', $method);
            $this->assertSame('http://faces.test/embed', $url);
            // Profiler fatals if body is array+resource (normalizeBody → Closure → explode).
            $this->assertIsString($options['body'] ?? null);
            $this->assertStringContainsString('name="file"', $options['body']);
            $headers = implode("\n", $options['headers'] ?? []);
            $this->assertStringContainsString('multipart/form-data', $headers);

            return new MockResponse(json_encode(['embedding' => $embedding], \JSON_THROW_ON_ERROR));
        });

        $client = new HttpFaceEmbeddingClient($http, 'http://faces.test');
        $result = $client->embedUpload($this->fixtureUpload());

        $this->assertSame($embedding, $result);
    }

    public function testDetectUploadDecodesCrops(): void
    {
        $embedding = array_fill(0, 8, 0.25);
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($embedding): MockResponse {
            $this->assertSame('http://faces.test/detect', $url);
            $this->assertIsString($options['body'] ?? null);

            return new MockResponse(json_encode([
                'faces' => [[
                    'embedding' => $embedding,
                    'x' => 1,
                    'y' => 2,
                    'width' => 3,
                    'height' => 4,
                    'cropJpeg' => base64_encode('jpeg-bytes'),
                ]],
            ], \JSON_THROW_ON_ERROR));
        });

        $client = new HttpFaceEmbeddingClient($http, 'http://faces.test');
        $result = $client->detectUpload($this->fixtureUpload());

        $this->assertCount(1, $result);
        $this->assertSame($embedding, $result[0]['embedding']);
        $this->assertSame(1.0, $result[0]['x']);
        $this->assertSame('jpeg-bytes', $result[0]['cropJpeg']);
    }

    private function fixtureUpload(): UploadedFile
    {
        $source = \dirname(__DIR__).'/fixtures/sample.jpg';
        $copy = tempnam(sys_get_temp_dir(), 'face-embed').'.jpg';
        copy($source, $copy);

        return new UploadedFile($copy, 'face.jpg', 'image/jpeg', null, true);
    }
}
