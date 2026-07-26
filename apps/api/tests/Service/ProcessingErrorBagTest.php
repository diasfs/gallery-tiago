<?php

namespace App\Tests\Service;

use App\Service\ProcessingErrorBag;
use PHPUnit\Framework\TestCase;

final class ProcessingErrorBagTest extends TestCase
{
    public function testSetAddsPrefixedLine(): void
    {
        $this->assertSame(
            'faces: boom',
            ProcessingErrorBag::set(null, 'faces', 'boom'),
        );
    }

    public function testSetReplacesExistingStageLineAndKeepsOthers(): void
    {
        $current = "media: disk full\nfaces: old";
        $this->assertSame(
            "media: disk full\nfaces: new",
            ProcessingErrorBag::set($current, 'faces', 'new'),
        );
    }

    public function testClearRemovesStageLineAndReturnsNullWhenEmpty(): void
    {
        $this->assertSame(
            'media: disk full',
            ProcessingErrorBag::clear("media: disk full\nfaces: boom", 'faces'),
        );
        $this->assertNull(ProcessingErrorBag::clear('faces: boom', 'faces'));
    }

    public function testSetNormalizesMessageWhitespace(): void
    {
        $this->assertSame(
            'tags: timeout',
            ProcessingErrorBag::set(null, 'tags', "  timeout\n"),
        );
    }

    public function testSetCollapsesInternalNewlinesToSingleLine(): void
    {
        $this->assertSame(
            'media: line one line two',
            ProcessingErrorBag::set(null, 'media', "line one\nline two"),
        );
    }
}
