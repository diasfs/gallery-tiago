<?php

namespace App\Support;

final class ImageRotation
{
    /**
     * @return array{x: float, y: float, width: float, height: float}
     */
    public static function transformBox(
        float $x,
        float $y,
        float $width,
        float $height,
        int $imageWidth,
        int $imageHeight,
        int $degreesClockwise,
    ): array {
        $degreesClockwise = ((int) (360 + ($degreesClockwise % 360))) % 360;
        if (!\in_array($degreesClockwise, [90, 180, 270], true)) {
            throw new \InvalidArgumentException('degreesClockwise must be 90, 180, or 270.');
        }

        return match ($degreesClockwise) {
            90 => [
                'x' => $imageHeight - $y - $height,
                'y' => $x,
                'width' => $height,
                'height' => $width,
            ],
            180 => [
                'x' => $imageWidth - $x - $width,
                'y' => $imageHeight - $y - $height,
                'width' => $width,
                'height' => $height,
            ],
            270 => [
                'x' => $y,
                'y' => $imageWidth - $x - $width,
                'width' => $height,
                'height' => $width,
            ],
        };
    }

    public static function vipsAngle(int $degreesClockwise): string
    {
        $degreesClockwise = ((int) (360 + ($degreesClockwise % 360))) % 360;

        return match ($degreesClockwise) {
            90 => 'd90',
            180 => 'd180',
            270 => 'd270',
            default => throw new \InvalidArgumentException('degreesClockwise must be 90, 180, or 270.'),
        };
    }
}
