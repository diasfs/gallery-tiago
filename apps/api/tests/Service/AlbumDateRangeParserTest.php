<?php

namespace App\Tests\Service;

use App\Service\AlbumDateRangeParser;
use PHPUnit\Framework\TestCase;

final class AlbumDateRangeParserTest extends TestCase
{
    private AlbumDateRangeParser $parser;

    protected function setUp(): void
    {
        $this->parser = new AlbumDateRangeParser();
    }

    public function testParsesSimpleHyphenRange(): void
    {
        $result = $this->parser->parse('01/06/2024 - 05/06/2024');

        $this->assertNotNull($result);
        $this->assertSame('2024-06-01', $result['start']->format('Y-m-d'));
        $this->assertSame('2024-06-05', $result['end']->format('Y-m-d'));
        $this->assertSame(null, $result['descriptionWithoutRange']);
    }

    public function testParsesEnDashAndSurroundingText(): void
    {
        $result = $this->parser->parse("Férias na Europa\n15/07/2023 – 22/07/2023");

        $this->assertNotNull($result);
        $this->assertSame('2023-07-15', $result['start']->format('Y-m-d'));
        $this->assertSame('2023-07-22', $result['end']->format('Y-m-d'));
        $this->assertSame('Férias na Europa', $result['descriptionWithoutRange']);
    }

    public function testRejectsInvertedRange(): void
    {
        // Inverted range fails as a period; may still match the first day as a single date.
        $result = $this->parser->parse('05/06/2024 - 01/06/2024');
        $this->assertNotNull($result);
        $this->assertSame('2024-06-05', $result['start']->format('Y-m-d'));
        $this->assertNull($result['end']);
    }

    public function testReturnsNullWithoutDate(): void
    {
        $this->assertNull($this->parser->parse('Sem datas aqui'));
        $this->assertNull($this->parser->parse(null));
        $this->assertNull($this->parser->parse(''));
    }

    public function testAllowsSameStartAndEnd(): void
    {
        $result = $this->parser->parse('10/03/2022 - 10/03/2022');

        $this->assertNotNull($result);
        $this->assertSame('2022-03-10', $result['start']->format('Y-m-d'));
        $this->assertSame('2022-03-10', $result['end']->format('Y-m-d'));
    }

    public function testParsesSingleDate(): void
    {
        $result = $this->parser->parse('10/03/2022');

        $this->assertNotNull($result);
        $this->assertSame('2022-03-10', $result['start']->format('Y-m-d'));
        $this->assertNull($result['end']);
        $this->assertSame(null, $result['descriptionWithoutRange']);
    }

    public function testParsesSingleDateWithSurroundingText(): void
    {
        $result = $this->parser->parse("Aniversário\n15/07/2023");

        $this->assertNotNull($result);
        $this->assertSame('2023-07-15', $result['start']->format('Y-m-d'));
        $this->assertNull($result['end']);
        $this->assertSame('Aniversário', $result['descriptionWithoutRange']);
    }

    public function testPrefersRangeOverSingleDate(): void
    {
        $result = $this->parser->parse('01/06/2024 - 05/06/2024 e mais texto');

        $this->assertNotNull($result);
        $this->assertSame('2024-06-05', $result['end']?->format('Y-m-d'));
    }
}
