<?php

namespace App\Service;

interface GeocoderInterface
{
    /**
     * @return list<array{name: string, city: ?string, country: ?string, latitude: float, longitude: float, displayName: string}>
     */
    public function search(string $query): array;

    /**
     * @return array{name: string, city: ?string, country: ?string, latitude: float, longitude: float, displayName: string}|null
     */
    public function reverse(float $latitude, float $longitude): ?array;
}
