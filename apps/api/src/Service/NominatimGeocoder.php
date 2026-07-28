<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class NominatimGeocoder implements GeocoderInterface
{
    private const BASE = 'https://nominatim.openstreetmap.org';
    private const USER_AGENT = 'GalleryV4/1.0 (admin geocode)';

    private float $lastRequestAt = 0.0;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly int $minIntervalMs = 1000,
    ) {
    }

    /**
     * @return list<array{name: string, city: ?string, country: ?string, latitude: float, longitude: float, displayName: string}>
     */
    public function search(string $query): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return [];
        }

        $this->throttle();

        try {
            $response = $this->httpClient->request('GET', self::BASE.'/search', [
                'query' => [
                    'format' => 'jsonv2',
                    'addressdetails' => 1,
                    'limit' => 8,
                    'q' => $query,
                ],
                'headers' => [
                    'User-Agent' => self::USER_AGENT,
                ],
                'timeout' => 8,
            ]);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                return [];
            }
            /** @var list<array<string, mixed>> $payload */
            $payload = $response->toArray(false);
        } catch (TransportExceptionInterface|\JsonException|\Throwable) {
            return [];
        }

        $results = [];
        foreach ($payload as $item) {
            $mapped = $this->mapItem($item);
            if (null !== $mapped) {
                $results[] = $mapped;
            }
        }

        return $results;
    }

    /**
     * @return array{name: string, city: ?string, country: ?string, latitude: float, longitude: float, displayName: string}|null
     */
    public function reverse(float $latitude, float $longitude): ?array
    {
        $this->throttle();

        try {
            $response = $this->httpClient->request('GET', self::BASE.'/reverse', [
                'query' => [
                    'format' => 'jsonv2',
                    'addressdetails' => 1,
                    'lat' => $latitude,
                    'lon' => $longitude,
                ],
                'headers' => [
                    'User-Agent' => self::USER_AGENT,
                ],
                'timeout' => 8,
            ]);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                return null;
            }
            /** @var array<string, mixed> $payload */
            $payload = $response->toArray(false);
        } catch (TransportExceptionInterface|\JsonException|\Throwable) {
            return null;
        }

        return $this->mapItem($payload);
    }

    /**
     * @param array<string, mixed> $item
     *
     * @return array{name: string, city: ?string, country: ?string, latitude: float, longitude: float, displayName: string}|null
     */
    private function mapItem(array $item): ?array
    {
        if (!isset($item['lat'], $item['lon']) || !\is_numeric($item['lat']) || !\is_numeric($item['lon'])) {
            return null;
        }

        $displayName = isset($item['display_name']) && \is_string($item['display_name'])
            ? $item['display_name']
            : '';

        $name = isset($item['name']) && \is_string($item['name']) && '' !== $item['name']
            ? $item['name']
            : ($displayName !== '' ? explode(',', $displayName)[0] : 'Local');

        $address = isset($item['address']) && \is_array($item['address']) ? $item['address'] : [];
        $city = $this->firstString($address, ['city', 'town', 'village', 'municipality', 'hamlet']);
        $country = $this->firstString($address, ['country']);

        return [
            'name' => $name,
            'city' => $city,
            'country' => $country,
            'latitude' => (float) $item['lat'],
            'longitude' => (float) $item['lon'],
            'displayName' => $displayName !== '' ? $displayName : $name,
        ];
    }

    /**
     * @param array<mixed> $address
     * @param list<string> $keys
     */
    private function firstString(array $address, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($address[$key]) && \is_string($address[$key]) && '' !== $address[$key]) {
                return $address[$key];
            }
        }

        return null;
    }

    private function throttle(): void
    {
        if ($this->minIntervalMs <= 0) {
            $this->lastRequestAt = microtime(true);

            return;
        }

        $now = microtime(true);
        if ($this->lastRequestAt > 0) {
            $elapsedMs = ($now - $this->lastRequestAt) * 1000;
            $waitMs = $this->minIntervalMs - $elapsedMs;
            if ($waitMs > 0) {
                usleep((int) round($waitMs * 1000));
            }
        }
        $this->lastRequestAt = microtime(true);
    }
}
