<?php

namespace App\Service;

use App\Entity\Face;
use App\Entity\FaceGalleryScan;
use App\Entity\Person;
use App\Repository\FaceGalleryScanMatchRepository;
use App\Repository\FaceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class FaceGalleryScanConfirmer
{
    public function __construct(
        private readonly FaceGalleryScanMatchRepository $matches,
        private readonly FaceRepository $faces,
        private readonly MediaStorage $storage,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function confirm(FaceGalleryScan $scan, string $name): Person
    {
        if (FaceGalleryScan::STATUS_DONE !== $scan->getStatus()) {
            throw new BadRequestHttpException('Scan must be done before confirming.');
        }

        $trimmed = trim($name);
        if ('' === $trimmed) {
            throw new BadRequestHttpException('name is required.');
        }

        $person = new Person();
        $person->setName($trimmed);
        $person->setIsNamed(true);
        $this->em->persist($person);

        /** @var list<array{0: Face, 1: ?string}> $pendingCrops */
        $pendingCrops = [];

        foreach ($this->matches->findByScanOrdered($scan) as $match) {
            if (!$match->isSelected()) {
                continue;
            }

            $photo = $match->getPhoto();
            $existing = $this->faces->findOneByPhotoAndPerson($photo, $person);
            if (null !== $existing) {
                continue;
            }

            $face = new Face($photo);
            $face->setPerson($person);
            $face->setX($match->getX());
            $face->setY($match->getY());
            $face->setWidth($match->getWidth());
            $face->setHeight($match->getHeight());
            $face->setEmbedding($match->getEmbedding());
            $this->em->persist($face);
            $pendingCrops[] = [$face, $match->getCropPath()];
        }

        $this->em->flush();

        foreach ($pendingCrops as [$face, $sourceCrop]) {
            $face->setCropPath($this->storage->copyFaceCrop($sourceCrop, (string) $face->getId()));
        }

        $this->em->flush();

        return $person;
    }
}
