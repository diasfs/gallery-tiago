<?php

namespace App\Service;

use App\Entity\Person;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Soft-discards people into trash or permanently purges them with faces/crops.
 */
final class PersonDeleter
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MediaStorage $storage,
    ) {
    }

    public function discard(Person $person): void
    {
        if ($person->isDeleted()) {
            return;
        }

        $person->setDeletedAt(new \DateTimeImmutable());
        $this->em->flush();
    }

    public function restore(Person $person): void
    {
        if (!$person->isDeleted()) {
            return;
        }

        $person->setDeletedAt(null);
        $this->em->flush();
    }

    public function purge(Person $person): void
    {
        $this->storage->deleteRelative($person->getAvatarPath());
        $person->setAvatarPath(null);
        $person->setAvatarFace(null);
        $this->em->flush();

        foreach ($person->getFaces()->toArray() as $face) {
            $this->storage->deleteRelative($face->getCropPath());
            $this->em->remove($face);
        }

        $this->em->remove($person);
        $this->em->flush();
    }
}
