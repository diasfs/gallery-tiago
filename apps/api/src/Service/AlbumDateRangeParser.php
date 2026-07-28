<?php

namespace App\Service;

/**
 * Extracts dd/mm/yyyy – dd/mm/yyyy ranges or a single dd/mm/yyyy from legacy album descriptions.
 *
 * @phpstan-type ParsedRange array{
 *   start: \DateTimeImmutable,
 *   end: ?\DateTimeImmutable,
 *   matchedSubstring: string,
 *   descriptionWithoutRange: ?string
 * }
 */
final class AlbumDateRangeParser
{
    private const RANGE_PATTERN = '/(?P<match>(?P<d1>\d{1,2})\/(?P<m1>\d{1,2})\/(?P<y1>\d{4})\s*[-–—]\s*(?P<d2>\d{1,2})\/(?P<m2>\d{1,2})\/(?P<y2>\d{4}))/u';

    private const SINGLE_PATTERN = '/(?P<match>(?P<d>\d{1,2})\/(?P<m>\d{1,2})\/(?P<y>\d{4}))/u';

    /**
     * @return ParsedRange|null
     */
    public function parse(?string $description): ?array
    {
        if (null === $description || '' === trim($description)) {
            return null;
        }

        return $this->parseRange($description) ?? $this->parseSingle($description);
    }

    /**
     * @return ParsedRange|null
     */
    private function parseRange(string $description): ?array
    {
        if (!preg_match(self::RANGE_PATTERN, $description, $matches)) {
            return null;
        }

        $start = $this->makeDate((int) $matches['y1'], (int) $matches['m1'], (int) $matches['d1']);
        $end = $this->makeDate((int) $matches['y2'], (int) $matches['m2'], (int) $matches['d2']);
        if (null === $start || null === $end || $end < $start) {
            return null;
        }

        return $this->result($start, $end, $matches['match'], $description);
    }

    /**
     * @return ParsedRange|null
     */
    private function parseSingle(string $description): ?array
    {
        if (!preg_match(self::SINGLE_PATTERN, $description, $matches)) {
            return null;
        }

        $start = $this->makeDate((int) $matches['y'], (int) $matches['m'], (int) $matches['d']);
        if (null === $start) {
            return null;
        }

        return $this->result($start, null, $matches['match'], $description);
    }

    /**
     * @return ParsedRange
     */
    private function result(
        \DateTimeImmutable $start,
        ?\DateTimeImmutable $end,
        string $matched,
        string $description,
    ): array {
        $cleaned = trim(preg_replace('/\s+/u', ' ', str_replace($matched, '', $description)) ?? '');
        if ('' === $cleaned) {
            $cleaned = null;
        }

        return [
            'start' => $start,
            'end' => $end,
            'matchedSubstring' => $matched,
            'descriptionWithoutRange' => $cleaned,
        ];
    }

    private function makeDate(int $year, int $month, int $day): ?\DateTimeImmutable
    {
        if (!checkdate($month, $day, $year)) {
            return null;
        }

        return new \DateTimeImmutable(\sprintf('%04d-%02d-%02dT00:00:00+00:00', $year, $month, $day));
    }
}
