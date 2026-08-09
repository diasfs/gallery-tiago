<?php

namespace App\Service;

use App\Entity\FaceGalleryScan;
use App\Message\ScanFaceMessage;
use App\Repository\PhotoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final class FaceGalleryScanEnqueuer
{
    public const BATCH_SIZE = 500;

    public function __construct(
        private readonly PhotoRepository $photos,
        private readonly MessageBusInterface $bus,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function enqueueNextBatch(FaceGalleryScan $scan): int
    {
        if ($scan->isTerminal()) {
            return 0;
        }

        if ($scan->getEnqueuedPhotos() >= $scan->getTotalPhotos()) {
            return 0;
        }

        $photoIds = $this->photos->findIdsWithAvif(
            $scan->getEnqueuedPhotos(),
            self::BATCH_SIZE,
        );

        if ([] === $photoIds) {
            return 0;
        }

        foreach ($photoIds as $photoId) {
            $this->bus->dispatch(new ScanFaceMessage((string) $scan->getId(), $photoId));
        }

        $scan->incrementEnqueuedPhotos(\count($photoIds));
        if (FaceGalleryScan::STATUS_PENDING === $scan->getStatus()) {
            $scan->setStatus(FaceGalleryScan::STATUS_RUNNING);
        }
        $this->em->flush();

        return \count($photoIds);
    }
}
