<?php

namespace App\Tests\Entity;

use App\Enum\AlbumVisibility;
use PHPUnit\Framework\TestCase;

final class AlbumVisibilityTest extends TestCase
{
    public function testCases(): void
    {
        $this->assertSame(['public', 'unlisted', 'private'], array_map(
            static fn (AlbumVisibility $v) => $v->value,
            AlbumVisibility::cases()
        ));
    }
}
