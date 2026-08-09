<?php

namespace App\Service;

use App\Entity\FaceGalleryScan;
use App\Repository\FaceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class FaceGalleryScanDeleter
{
    public function __construct(
        private readonly FaceRepository $faces,
        private readonly MediaStorage $storage,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function delete(FaceGalleryScan $scan): void
    {
        if (!$scan->isTerminal()) {
            throw new BadRequestHttpException('Only finished or cancelled scans can be removed.');
        }

        $scanId = (string) $scan->getId();
        $this->migrateLegacyFaceCrops($scanId);

        $this->storage->deleteFaceScanDirectory($scanId);
        $this->em->remove($scan);
        $this->em->flush();
    }

    private function migrateLegacyFaceCrops(string $scanId): void
    {
        $prefix = $this->storage->faceScanDirectoryRelative($scanId).'/';
        $dirty = false;

        foreach ($this->faces->findWithCropPathPrefix($prefix) as $face) {
            $newPath = $this->storage->copyFaceCrop($face->getCropPath(), (string) $face->getId());
            if (null !== $newPath) {
                $face->setCropPath($newPath);
                $dirty = true;
            }
        }

        if ($dirty) {
            $this->em->flush();
        }
    }
}
