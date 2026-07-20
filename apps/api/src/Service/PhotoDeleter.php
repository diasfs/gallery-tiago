<?php

namespace App\Service;

use App\Entity\Photo;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Deletes a photo's on-disk media and the Photo entity.
 *
 * Face rows and their crop files are intentionally retained: photo_id is
 * SET NULL so person avatars and face galleries survive source deletion.
 */
final class PhotoDeleter
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MediaStorage $storage,
    ) {
    }

    public function delete(Photo $photo): void
    {
        $this->detachFaces($photo);
        $this->deleteMedia($photo);
        $this->em->remove($photo);
        $this->em->flush();
    }

    /**
     * @param list<Photo> $photos
     */
    public function deleteMany(array $photos): void
    {
        foreach ($photos as $photo) {
            $this->detachFaces($photo);
            $this->deleteMedia($photo);
            $this->em->remove($photo);
        }
        $this->em->flush();
    }

    public function deleteMedia(Photo $photo): void
    {
        $this->storage->deleteRelative($photo->getOriginalPath());
        $this->storage->deleteRelative($photo->getAvifPath());

        foreach ($photo->getThumbPaths() as $thumbPath) {
            if (\is_string($thumbPath)) {
                $this->storage->deleteRelative($thumbPath);
            }
        }
    }

    private function detachFaces(Photo $photo): void
    {
        foreach ($photo->getFaces()->toArray() as $face) {
            $face->setPhoto(null);
        }
    }
}
