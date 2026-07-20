<?php

namespace App\Service;

use App\Entity\Album;
use App\Entity\Photo;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Recursively deletes an album, its descendants, photos, and on-disk media.
 */
final class AlbumDeleter
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MediaStorage $storage,
    ) {
    }

    public function delete(Album $album): void
    {
        foreach ($album->getChildren()->toArray() as $child) {
            $this->delete($child);
        }

        $album->setCoverPhoto(null);

        foreach ($album->getPhotos()->toArray() as $photo) {
            $this->deletePhotoMedia($photo);
            $this->em->remove($photo);
        }

        $this->em->remove($album);
        $this->em->flush();
    }

    private function deletePhotoMedia(Photo $photo): void
    {
        $this->storage->deleteRelative($photo->getOriginalPath());
        $this->storage->deleteRelative($photo->getAvifPath());

        foreach ($photo->getThumbPaths() ?? [] as $thumbPath) {
            if (\is_string($thumbPath)) {
                $this->storage->deleteRelative($thumbPath);
            }
        }

        foreach ($photo->getFaces() as $face) {
            $this->storage->deleteRelative($face->getCropPath());
        }
    }
}
