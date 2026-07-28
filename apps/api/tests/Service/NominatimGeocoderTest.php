<?php

namespace App\Tests\Service;

use App\Service\NominatimGeocoder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class NominatimGeocoderTest extends TestCase
{
    public function testSearchMapsNominatimResults(): void
    {
        $payload = json_encode([
            [
                'name' => 'Tour Eiffel',
                'display_name' => 'Tour Eiffel, Paris, France',
                'lat' => '48.85837',
                'lon' => '2.294481',
                'address' => [
                    'city' => 'Paris',
                    'country' => 'France',
                ],
            ],
        ], \JSON_THROW_ON_ERROR);

        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($payload): MockResponse {
            $this->assertSame('GET', $method);
            $this->assertStringContainsString('nominatim.openstreetmap.org/search', $url);
            $this->assertStringContainsString('q=Eiffel', $url);
            $this->assertStringContainsString('format=jsonv2', $url);
            $this->assertStringContainsString('addressdetails=1', $url);
            $headers = $options['headers'] ?? [];
            $ua = $this->headerValue($headers, 'User-Agent');
            $this->assertNotNull($ua);
            $this->assertStringContainsString('GalleryV4', $ua);

            return new MockResponse($payload, ['http_code' => 200]);
        });

        $geocoder = new NominatimGeocoder($http, minIntervalMs: 0);
        $results = $geocoder->search('Eiffel');

        $this->assertCount(1, $results);
        $this->assertSame('Tour Eiffel', $results[0]['name']);
        $this->assertSame('Paris', $results[0]['city']);
        $this->assertSame('France', $results[0]['country']);
        $this->assertSame(48.85837, $results[0]['latitude']);
        $this->assertSame(2.294481, $results[0]['longitude']);
        $this->assertSame('Tour Eiffel, Paris, France', $results[0]['displayName']);
    }

    public function testSearchUsesTownWhenCityMissing(): void
    {
        $payload = json_encode([
            [
                'name' => 'Village Square',
                'display_name' => 'Village Square, Smallville, Canada',
                'lat' => '45.0',
                'lon' => '-75.0',
                'address' => [
                    'town' => 'Smallville',
                    'country' => 'Canada',
                ],
            ],
        ], \JSON_THROW_ON_ERROR);

        $http = new MockHttpClient([new MockResponse($payload)]);
        $geocoder = new NominatimGeocoder($http, minIntervalMs: 0);

        $results = $geocoder->search('Smallville');
        $this->assertSame('Smallville', $results[0]['city']);
    }

    public function testSearchReturnsEmptyForBlankQuery(): void
    {
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects($this->never())->method('request');

        $geocoder = new NominatimGeocoder($http, minIntervalMs: 0);
        $this->assertSame([], $geocoder->search(' '));
        $this->assertSame([], $geocoder->search('a'));
    }

    public function testReverseMapsNominatimResult(): void
    {
        $payload = json_encode([
            'name' => 'Praça da Sé',
            'display_name' => 'Praça da Sé, São Paulo, Brazil',
            'lat' => '-23.5505',
            'lon' => '-46.6333',
            'address' => [
                'city' => 'São Paulo',
                'country' => 'Brazil',
            ],
        ], \JSON_THROW_ON_ERROR);

        $http = new MockHttpClient(function (string $method, string $url) use ($payload): MockResponse {
            $this->assertSame('GET', $method);
            $this->assertStringContainsString('nominatim.openstreetmap.org/reverse', $url);
            $this->assertStringContainsString('lat=-23.5505', $url);
            $this->assertStringContainsString('lon=-46.6333', $url);

            return new MockResponse($payload);
        });

        $geocoder = new NominatimGeocoder($http, minIntervalMs: 0);
        $result = $geocoder->reverse(-23.5505, -46.6333);

        $this->assertNotNull($result);
        $this->assertSame('Praça da Sé', $result['name']);
        $this->assertSame('São Paulo', $result['city']);
        $this->assertSame('Brazil', $result['country']);
        $this->assertSame(-23.5505, $result['latitude']);
        $this->assertSame(-46.6333, $result['longitude']);
    }

    public function testReverseReturnsNullOnError(): void
    {
        $http = new MockHttpClient([new MockResponse('error', ['http_code' => 500])]);
        $geocoder = new NominatimGeocoder($http, minIntervalMs: 0);

        $this->assertNull($geocoder->reverse(0.0, 0.0));
    }

    public function testRateLimitWaitsBetweenRequests(): void
    {
        $http = new MockHttpClient([
            new MockResponse('[]'),
            new MockResponse('[]'),
        ]);
        $geocoder = new NominatimGeocoder($http, minIntervalMs: 50);

        $start = hrtime(true);
        $geocoder->search('ab');
        $geocoder->search('cd');
        $elapsedMs = (hrtime(true) - $start) / 1e6;

        $this->assertGreaterThanOrEqual(45.0, $elapsedMs);
    }

    /** @param list<string> $headers */
    private function headerValue(array $headers, string $name): ?string
    {
        foreach ($headers as $header) {
            if (\is_string($header) && str_starts_with(strtolower($header), strtolower($name).':')) {
                return trim(substr($header, \strlen($name) + 1));
            }
        }

        return null;
    }
}
