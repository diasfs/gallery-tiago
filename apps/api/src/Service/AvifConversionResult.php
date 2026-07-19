<?php

namespace App\Service;

final class AvifConversionResult
{
    public function __construct(
        public readonly int $width,
        public readonly int $height,
    ) {
    }
}
