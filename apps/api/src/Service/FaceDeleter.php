<?php

namespace App\Service;

use App\Entity\Face;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Soft-discards faces (kept for restore on photo admin) or hard-purges with crops.
 */
final class FaceDeleter
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MediaStorage $storage,
    ) {
    }

    public function softDelete(Face $face): void
    {
        if ($face->isDeleted()) {
            return;
        }

        $person = $face->getPerson();
        if (null !== $person && $person->getAvatarFace()?->getId()?->equals($face->getId()) === true) {
            $person->setAvatarFace(null);
        }

        $face->setDeletedAt(new \DateTimeImmutable());
        $this->em->flush();
    }

    public function restore(Face $face): void
    {
        if (!$face->isDeleted()) {
            return;
        }

        $face->setDeletedAt(null);
        $this->em->flush();
    }

    public function purge(Face $face): void
    {
        $this->storage->deleteRelative($face->getCropPath());
        $this->em->remove($face);
    }
}
