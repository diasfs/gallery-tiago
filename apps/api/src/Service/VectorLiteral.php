<?php

namespace App\Service;

final class VectorLiteral
{
    /** @param float[] $embedding */
    public static function format(array $embedding): string
    {
        return '['.implode(',', array_map(static fn (float $v): string => (string) $v, $embedding)).']';
    }
}
