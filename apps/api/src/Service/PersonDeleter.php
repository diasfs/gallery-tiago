<?php

namespace App\Service;

use App\Entity\Person;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Deletes a Person, its Face rows, and any on-disk face crop files.
 */
final class PersonDeleter
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MediaStorage $storage,
    ) {
    }

    public function delete(Person $person): void
    {
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
