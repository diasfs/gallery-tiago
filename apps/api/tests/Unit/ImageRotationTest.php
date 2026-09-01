<?php

namespace App\Tests\Unit;

use App\Support\ImageRotation;
use PHPUnit\Framework\TestCase;

final class ImageRotationTest extends TestCase
{
    public function testTransformBoxRotatesNinetyDegreesClockwise(): void
    {
        $box = ImageRotation::transformBox(100.0, 50.0, 80.0, 100.0, 800, 600, 90);

        $this->assertSame(450.0, $box['x']);
        $this->assertSame(100.0, $box['y']);
        $this->assertSame(100.0, $box['width']);
        $this->assertSame(80.0, $box['height']);
    }
}
