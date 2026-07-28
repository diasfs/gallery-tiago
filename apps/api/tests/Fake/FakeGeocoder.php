<?php

namespace App\Tests\Fake;

use App\Service\GeocoderInterface;

final class FakeGeocoder implements GeocoderInterface
{
    /** @var list<array{name: string, city: ?string, country: ?string, latitude: float, longitude: float, displayName: string}> */
    public static array $searchResults = [];

    /** @var array{name: string, city: ?string, country: ?string, latitude: float, longitude: float, displayName: string}|null */
    public static ?array $reverseResult = null;

    public static int $searchCalls = 0;
    public static int $reverseCalls = 0;
    public static ?string $lastSearchQuery = null;
    public static ?float $lastReverseLat = null;
    public static ?float $lastReverseLon = null;

    public static function reset(): void
    {
        self::$searchResults = [];
        self::$reverseResult = null;
        self::$searchCalls = 0;
        self::$reverseCalls = 0;
        self::$lastSearchQuery = null;
        self::$lastReverseLat = null;
        self::$lastReverseLon = null;
    }

    public function search(string $query): array
    {
        ++self::$searchCalls;
        self::$lastSearchQuery = $query;

        return self::$searchResults;
    }

    public function reverse(float $latitude, float $longitude): ?array
    {
        ++self::$reverseCalls;
        self::$lastReverseLat = $latitude;
        self::$lastReverseLon = $longitude;

        return self::$reverseResult;
    }
}
