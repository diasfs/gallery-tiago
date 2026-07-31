<?php

namespace App\Support;

final class ReservedAlbumSlugs
{
    /** @var list<string> */
    public const SLUGS = [
        'search',
        'map',
        'timeline',
        'memories',
        'popular',
        'albums',
        'photos',
        'people',
        'tags',
        'locations',
        'admin',
        'api',
        'converted',
        'originals',
        'faces',
        'avatars',
    ];

    public const ROUTE_SLUG_PATTERN = '(?!search|map|timeline|memories|popular|albums|photos|people|tags|locations|admin|api|converted|originals|faces|avatars)[^/]+';

    public const FILENAME_PATTERN = '.+\.[a-zA-Z0-9]{2,5}';

    public static function isReserved(string $slug): bool
    {
        return \in_array(strtolower($slug), self::SLUGS, true);
    }

    /** Regex fragment for Symfony route requirements (negative lookahead). */
    public static function routeSlugPattern(): string
    {
        return self::ROUTE_SLUG_PATTERN;
    }
}
