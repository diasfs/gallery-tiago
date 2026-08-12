<?php

namespace App\Service;

use App\Entity\Photo;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Process\Process;

/**
 * On-demand JPEG export from the gallery master (AVIF) or original fallback.
 */
final class PhotoJpegExporter
{
    public function __construct(
        private readonly MediaStorage $storage,
        private readonly string $vipsBinary = 'vips',
        private readonly int $jpegQuality = 85,
    ) {
    }

    /** @return string absolute path to a temp JPEG file (caller deletes) */
    public function export(Photo $photo): string
    {
        $relative = $photo->getAvifPath() ?? $photo->getOriginalPath();
        if (null === $relative || '' === $relative) {
            throw new NotFoundHttpException('Photo has no downloadable image.');
        }

        $source = $this->storage->absolutePath($relative);
        if (!is_file($source)) {
            throw new NotFoundHttpException('Photo file not found.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'gallery-jpeg-');
        if (false === $tmp) {
            throw new \RuntimeException('Could not create temp file for JPEG export.');
        }
        $destination = $tmp.'.jpg';
        if (!rename($tmp, $destination)) {
            @unlink($tmp);
            throw new \RuntimeException('Could not create temp file for JPEG export.');
        }

        $process = new Process([
            $this->vipsBinary,
            'copy',
            $source,
            \sprintf('%s[Q=%d]', $destination, $this->jpegQuality),
        ]);
        $process->setTimeout(120);
        $process->run();

        if (!$process->isSuccessful()) {
            @unlink($destination);
            throw new \RuntimeException('JPEG export failed: '.$process->getErrorOutput());
        }

        return $destination;
    }

    public function suggestFilename(Photo $photo): string
    {
        $title = $photo->getTitle();
        if (null !== $title && '' !== trim($title)) {
            $slug = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $title) ?? 'photo';
            $slug = trim($slug, '-');
            if ('' !== $slug) {
                return $slug.'.jpg';
            }
        }

        return (string) $photo->getId().'.jpg';
    }
}
