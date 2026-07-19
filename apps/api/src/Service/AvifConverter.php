<?php

namespace App\Service;

use Symfony\Component\Process\Process;

/**
 * Converts an original image into an AVIF master plus fixed-size AVIF
 * thumbnails, shelling out to the `vips` / `vipsthumbnail` CLI (libvips).
 *
 * The pecl `vips` extension baked into the API image only exposes the raw
 * procedural `vips_*` functions (no `Jcupitt\Vips\Image` OO wrapper, which
 * ships as a separate Composer package we haven't vendored). The CLI tools
 * are already installed for the same libvips build and are simple to shell
 * out to and to test, so this converter uses `Process` instead of the PHP
 * binding, per the task brief's fallback allowance.
 */
final class AvifConverter
{
    public const THUMBNAIL_SIZES = [320, 1280];

    public function __construct(
        private readonly string $vipsBinary = 'vips',
        private readonly string $vipsHeaderBinary = 'vipsheader',
        private readonly string $vipsThumbnailBinary = 'vipsthumbnail',
        private readonly int $masterQuality = 80,
        private readonly int $thumbQuality = 70,
    ) {
    }

    /**
     * @param array<int, string> $thumbAbsolutePathsBySize map of size => absolute destination path
     */
    public function convert(string $sourceAbsolutePath, string $masterAbsolutePath, array $thumbAbsolutePathsBySize): AvifConversionResult
    {
        if (!is_file($sourceAbsolutePath)) {
            throw new \RuntimeException(\sprintf('Source image "%s" does not exist.', $sourceAbsolutePath));
        }

        [$width, $height] = $this->readDimensions($sourceAbsolutePath);

        $this->ensureDirectoryFor($masterAbsolutePath);
        $this->run([
            $this->vipsBinary, 'copy',
            $sourceAbsolutePath,
            \sprintf('%s[Q=%d]', $masterAbsolutePath, $this->masterQuality),
        ]);

        foreach ($thumbAbsolutePathsBySize as $size => $path) {
            $this->ensureDirectoryFor($path);
            $this->run([
                $this->vipsThumbnailBinary,
                $sourceAbsolutePath,
                '--size', (string) $size,
                '-o', \sprintf('%s[Q=%d]', $path, $this->thumbQuality),
            ]);
        }

        return new AvifConversionResult($width, $height);
    }

    /** @return array{0: int, 1: int} */
    private function readDimensions(string $path): array
    {
        $width = (int) $this->run([$this->vipsHeaderBinary, '-f', 'width', $path]);
        $height = (int) $this->run([$this->vipsHeaderBinary, '-f', 'height', $path]);

        return [$width, $height];
    }

    private function run(array $command): string
    {
        $process = new Process($command);
        $process->setTimeout(60);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(\sprintf(
                'Command "%s" failed: %s',
                implode(' ', $command),
                trim($process->getErrorOutput()) ?: trim($process->getOutput()),
            ));
        }

        return trim($process->getOutput());
    }

    private function ensureDirectoryFor(string $absoluteFilePath): void
    {
        $dir = \dirname($absoluteFilePath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException(\sprintf('Unable to create directory "%s".', $dir));
        }
    }
}
