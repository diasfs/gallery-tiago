<?php

namespace App\Service;

use App\Entity\Photo;

final class PublicPhotoDisplay
{
    public function relativePath(Photo $photo): ?string
    {
        $thumbs = $photo->getThumbPaths();
        $numeric = [];
        foreach ($thumbs as $key => $path) {
            if (!\is_string($path) || '' === $path) {
                continue;
            }
            if (is_numeric($key)) {
                $numeric[(int) $key] = $path;
            }
        }

        if ([] !== $numeric) {
            ksort($numeric);
            $largestNumeric = $numeric[array_key_last($numeric)];
        } else {
            $largestNumeric = null;
        }

        $thumb = $thumbs['medium'] ?? $thumbs['small'] ?? $largestNumeric ?? null;
        if (null === $thumb && [] !== $thumbs) {
            $first = reset($thumbs);
            $thumb = \is_string($first) && '' !== $first ? $first : null;
        }

        return $thumb ?? $photo->getAvifPath() ?? $photo->getOriginalPath();
    }
}
