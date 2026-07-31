<?php

namespace App\Service;

use App\Entity\Photo;
use App\Repository\FaceRepository;
use App\Repository\PhotoRepository;
use Symfony\Component\Uid\Uuid;

final class FaceSimilarityService
{
    public function __construct(
        private readonly FaceRepository $faces,
        private readonly PhotoRepository $photos,
        private readonly float $clusterThreshold,
    ) {
    }

    /** @return Photo[] */
    public function findSimilarVisiblePhotos(Photo $photo, int $limit = 12): array
    {
        $ids = $this->faces->findSimilarVisiblePhotoIds($photo->getId(), $limit, $this->clusterThreshold);
        if (\count($ids) < $limit) {
            $tagIds = $this->photos->findSimilarVisiblePhotoIdsByTags($photo->getId(), $limit, $ids);
            foreach ($tagIds as $id) {
                if (!\in_array($id, $ids, true)) {
                    $ids[] = $id;
                }
                if (\count($ids) >= $limit) {
                    break;
                }
            }
        }

        if ([] === $ids) {
            return [];
        }

        return $this->photos->findVisibleByIdsPreservingOrder($ids);
    }

    /** @return list<array{sourcePersonId: string, targetPersonId: string, distance: float, faceCountA: int, faceCountB: int}> */
    public function findUnnamedMergeSuggestions(int $limit = 50): array
    {
        return $this->faces->findUnnamedMergeSuggestions($this->clusterThreshold, $limit);
    }

    /** @return list<array{personId: string, isNamed: bool, distance: float, name: ?string, avatarCropPath: ?string}> */
    public function searchPeopleByEmbedding(array $embedding, int $limit = 20): array
    {
        return $this->faces->findNearestPeople($embedding, $limit);
    }
}
