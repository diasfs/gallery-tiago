<?php

namespace App\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\Type;

/**
 * Maps a pgvector `vector(N)` column to/from a PHP array of floats.
 *
 * pgvector has no native Doctrine/DBAL support, so this custom type
 * round-trips the Postgres text representation `[1,2,3]` <-> `float[]`.
 * Dimension is fixed per-column via the migration DDL (vector(512)),
 * not enforced here.
 */
final class VectorType extends Type
{
    public const NAME = 'vector';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        $dimensions = $column['dimensions'] ?? 512;

        return sprintf('vector(%d)', $dimensions);
    }

    public function convertToPHPValue($value, AbstractPlatform $platform): ?array
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        $trimmed = trim($value, "[]");

        if ($trimmed === '') {
            return [];
        }

        return array_map('floatval', explode(',', $trimmed));
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_array($value)) {
            throw ConversionException::conversionFailedInvalidType($value, self::NAME, ['null', 'float[]']);
        }

        return '[' . implode(',', array_map(static fn (float $v): string => (string) $v, $value)) . ']';
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function requiresSQLCommentHint(AbstractPlatform $platform): bool
    {
        return true;
    }
}
