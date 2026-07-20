<?php

namespace App\Service;

use App\Entity\Album;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Recursively deletes an album, its descendants, photos, and on-disk media.
 */
final class AlbumDeleter
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PhotoDeleter $photoDeleter,
    ) {
    }

    public function delete(Album $album): void
    {
        foreach ($album->getChildren()->toArray() as $child) {
            $this->delete($child);
        }

        $album->setCoverPhoto(null);

        foreach ($album->getPhotos()->toArray() as $photo) {
            $this->photoDeleter->deleteMedia($photo);
            $this->em->remove($photo);
        }

        $this->em->remove($album);
        $this->em->flush();
    }
}
