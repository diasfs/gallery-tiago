<?php

namespace App\Tests\Service;

use App\Service\SearchText;
use PHPUnit\Framework\TestCase;

final class SearchTextTest extends TestCase
{
    public function testFoldStripsAccentsAndLowercases(): void
    {
        $this->assertSame('borussia', SearchText::fold('Borússia'));
        $this->assertSame('sao paulo', SearchText::fold('São Paulo'));
    }

    public function testLikePatternWrapsFoldedNeedle(): void
    {
        $this->assertSame('%borussia%', SearchText::likePattern('Borússia'));
    }
}
