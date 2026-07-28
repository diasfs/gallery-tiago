<?php

namespace App\Service;

/**
 * Accent-insensitive helpers for LIKE search needles.
 * Pair with SQL/DQL UNACCENT() on column values (PostgreSQL).
 */
final class SearchText
{
    public static function fold(string $value): string
    {
        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($value, \Normalizer::FORM_D);
            if (\is_string($normalized)) {
                $value = $normalized;
            }
        }

        $stripped = preg_replace('/\p{Mn}+/u', '', $value);
        if (\is_string($stripped)) {
            $value = $stripped;
        }

        return mb_strtolower($value);
    }

    public static function likePattern(string $query): string
    {
        return '%'.self::fold(trim($query)).'%';
    }
}
