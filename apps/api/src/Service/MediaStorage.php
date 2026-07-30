<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Resolves and writes files under MEDIA_ROOT. All paths persisted on the
 * `Photo` entity are stored *relative* to MEDIA_ROOT so the media volume can
 * be relocated without a data migration.
 */
final class MediaStorage
{
    public function __construct(
        #[Autowire(env: 'MEDIA_ROOT')]
        private readonly string $mediaRoot,
    ) {
    }

    /**
     * Moves an uploaded file into the originals tree and returns its path
     * relative to MEDIA_ROOT.
     */
    public function storeOriginal(UploadedFile $file, string $photoId): string
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'bin');
        $relativePath = \sprintf('originals/%s/%s.%s', substr($photoId, 0, 2), $photoId, $extension);

        $absoluteDir = \dirname($this->absolutePath($relativePath));
        $this->ensureDirectory($absoluteDir);
        $file->move($absoluteDir, basename($relativePath));

        return $relativePath;
    }

    /**
     * Moves an uploaded avatar into the avatars tree and returns its path
     * relative to MEDIA_ROOT (`avatars/{aa}/{personId}.{ext}`).
     */
    public function storePersonAvatar(UploadedFile $file, string $personId): string
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'bin');
        $relativePath = \sprintf('avatars/%s/%s.%s', substr($personId, 0, 2), $personId, $extension);

        $absoluteDir = \dirname($this->absolutePath($relativePath));
        $this->ensureDirectory($absoluteDir);
        $file->move($absoluteDir, basename($relativePath));

        return $relativePath;
    }

    /**
     * Copies an existing file into the originals tree (e.g. v3 import) and
     * returns its path relative to MEDIA_ROOT.
     */
    public function storeOriginalFromPath(string $absoluteSource, string $photoId): string
    {
        if (!is_file($absoluteSource)) {
            throw new \RuntimeException(\sprintf('Source file "%s" does not exist.', $absoluteSource));
        }

        $extension = strtolower(pathinfo($absoluteSource, \PATHINFO_EXTENSION) ?: 'bin');
        $relativePath = \sprintf('originals/%s/%s.%s', substr($photoId, 0, 2), $photoId, $extension);
        $absoluteDest = $this->absolutePath($relativePath);
        $this->ensureDirectory(\dirname($absoluteDest));

        if (!copy($absoluteSource, $absoluteDest)) {
            throw new \RuntimeException(\sprintf('Unable to copy "%s" to "%s".', $absoluteSource, $absoluteDest));
        }

        return $relativePath;
    }

    public function avifMasterPath(string $photoId): string
    {
        return \sprintf('converted/%s/%s/master.avif', substr($photoId, 0, 2), $photoId);
    }

    public function thumbPath(string $photoId, int $size): string
    {
        return \sprintf('converted/%s/%s/thumb-%d.avif', substr($photoId, 0, 2), $photoId, $size);
    }

    public function absolutePath(string $relativePath): string
    {
        return rtrim($this->mediaRoot, '/').'/'.ltrim($relativePath, '/');
    }

    public function ensureDirectoryFor(string $relativePath): void
    {
        $this->ensureDirectory(\dirname($this->absolutePath($relativePath)));
    }

    public function deleteRelative(?string $relativePath): void
    {
        if (null === $relativePath || '' === $relativePath) {
            return;
        }

        $absolute = $this->absolutePath($relativePath);
        if (is_file($absolute)) {
            @unlink($absolute);
        }
    }

    private function ensureDirectory(string $absoluteDir): void
    {
        if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0775, true) && !is_dir($absoluteDir)) {
            throw new \RuntimeException(\sprintf('Unable to create media directory "%s".', $absoluteDir));
        }
    }
}
