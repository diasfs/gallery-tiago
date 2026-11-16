<?php

namespace App\Service;

use App\Entity\Album;
use App\Entity\Photo;
use App\Repository\PhotoRepository;
use Doctrine\ORM\EntityManagerInterface;

final class PhotoMover
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PhotoRepository $photos,
    ) {
    }

    /**
     * @param list<Photo> $photos
     */
    public function move(array $photos, Album $target): void
    {
        $toMove = [];
        foreach ($photos as $photo) {
            if (!$photo->getAlbum()->getId()->equals($target->getId())) {
                $toMove[] = $photo;
            }
        }

        if ([] === $toMove) {
            throw new \InvalidArgumentException('Photos are already in this album.');
        }

        $conflicts = [];
        $seen = [];
        foreach ($toMove as $photo) {
            $filename = $photo->getFilename();
            if (null === $filename || '' === $filename) {
                continue;
            }
            if (isset($seen[$filename]) || $this->photos->existsFilenameInAlbum($target, $filename)) {
                $conflicts[] = $filename;
            }
            $seen[$filename] = true;
        }
        if ([] !== $conflicts) {
            $quoted = array_map(
                static fn (string $name): string => '"'.$name.'"',
                array_values(array_unique($conflicts)),
            );
            $list = implode(', ', $quoted);
            throw new \RuntimeException(
                1 === \count($quoted)
                    ? sprintf('A photo named %s already exists in this album.', $list)
                    : sprintf('Photos named %s already exist in this album.', $list),
            );
        }

        $sortOrder = $this->photos->nextSortOrderForAlbum($target);
        foreach ($toMove as $photo) {
            $source = $photo->getAlbum();
            if ($source->getCoverPhoto()?->getId()?->equals($photo->getId()) === true) {
                $source->setCoverPhoto(null);
            }
            $photo->setAlbum($target);
            $photo->setSortOrder($sortOrder);
            ++$sortOrder;
        }

        $this->em->flush();
    }
}
