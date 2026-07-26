<?php

namespace App\Service;

final class ProcessingErrorBag
{
    private const STAGES = ['media', 'faces', 'tags'];

    public static function set(?string $current, string $stage, string $message): string
    {
        self::assertStage($stage);
        $message = self::normalizeMessage($message);
        $lines = self::lines($current);
        $lines[$stage] = $stage.': '.$message;

        return self::join($lines);
    }

    public static function clear(?string $current, string $stage): ?string
    {
        self::assertStage($stage);
        $lines = self::lines($current);
        unset($lines[$stage]);
        $joined = self::join($lines);

        return '' === $joined ? null : $joined;
    }

    /** @return array<string, string> stage => full line */
    private static function lines(?string $current): array
    {
        $out = [];
        if (null === $current || '' === trim($current)) {
            return $out;
        }
        foreach (preg_split("/\r\n|\n|\r/", $current) as $line) {
            $line = trim($line);
            if ('' === $line) {
                continue;
            }
            foreach (self::STAGES as $stage) {
                if (str_starts_with($line, $stage.':')) {
                    $out[$stage] = $line;
                    continue 2;
                }
            }
            // Keep unrecognized legacy lines under a synthetic key so clear/set
            // of known stages does not destroy them.
            $out['_'.$line] = $line;
        }

        return $out;
    }

    /** @param array<string, string> $lines */
    private static function join(array $lines): string
    {
        $ordered = [];
        foreach (self::STAGES as $stage) {
            if (isset($lines[$stage])) {
                $ordered[] = $lines[$stage];
                unset($lines[$stage]);
            }
        }
        foreach ($lines as $line) {
            $ordered[] = $line;
        }

        return implode("\n", $ordered);
    }

    private static function normalizeMessage(string $message): string
    {
        $message = preg_replace('/\r\n|\n|\r/', ' ', $message);

        return trim(preg_replace('/\s+/u', ' ', $message ?? ''));
    }

    private static function assertStage(string $stage): void
    {
        if (!\in_array($stage, self::STAGES, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown processing stage "%s".', $stage));
        }
    }
}
