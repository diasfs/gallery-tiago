<?php

namespace App\Service;

use App\Entity\Person;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Merges an unwanted/duplicate `Person` cluster into another one: every
 * `Face` pointing at $source is reassigned to $target, and the now-empty
 * $source person is deleted (see spec §5 Person).
 */
final class PersonMerger
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MediaStorage $storage,
    ) {
    }

    public function merge(Person $source, Person $target): void
    {
        if ($source->getId()->equals($target->getId())) {
            throw new BadRequestHttpException('Cannot merge a person into itself.');
        }

        foreach ($source->getFaces() as $face) {
            $face->setPerson($target);
        }

        if (null === $target->getAvatarFace() && null !== $source->getAvatarFace()) {
            $target->setAvatarFace($source->getAvatarFace());
        }
        $source->setAvatarFace(null);

        if (null === $target->getAvatarPath() && null !== $source->getAvatarPath()) {
            $target->setAvatarPath($source->getAvatarPath());
            $source->setAvatarPath(null);
        } else {
            $this->storage->deleteRelative($source->getAvatarPath());
            $source->setAvatarPath(null);
        }

        $this->em->flush();

        $this->em->remove($source);
        $this->em->flush();
    }
}
