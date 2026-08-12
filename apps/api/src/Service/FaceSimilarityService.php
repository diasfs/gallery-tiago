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
        private readonly int $mergeSuggestionsMaxClusters,
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

    /**
     * @return array{
     *     items: list<array{sourcePersonId: string, targetPersonId: string, distance: float, faceCountA: int, faceCountB: int}>,
     *     analyzedClusterCount: int,
     *     truncated: bool,
     * }
     */
    public function findUnnamedMergeSuggestions(int $limit = 50): array
    {
        return $this->faces->findUnnamedMergeSuggestions(
            $this->clusterThreshold,
            $limit,
            $this->mergeSuggestionsMaxClusters,
        );
    }

    /** @return list<array{personId: string, isNamed: bool, distance: float, name: ?string, avatarCropPath: ?string}> */
    public function searchPeopleByEmbedding(array $embedding, int $limit = 20): array
    {
        return $this->faces->findNearestPeople($embedding, $limit);
    }

    /**
     * Cosine distance for L2-normalized InsightFace vectors (same as pgvector <=> / worker scan).
     *
     * @param float[] $a
     * @param float[] $b
     */
    public function cosineDistance(array $a, array $b): float
    {
        $dot = 0.0;
        $n = min(\count($a), \count($b));
        for ($i = 0; $i < $n; ++$i) {
            $dot += (float) $a[$i] * (float) $b[$i];
        }

        return 1.0 - $dot;
    }

    /**
     * @param float[]      $candidate
     * @param list<float[]> $references
     */
    public function matchesAnyReference(array $candidate, array $references, float $threshold): bool
    {
        foreach ($references as $reference) {
            if ($this->cosineDistance($candidate, $reference) <= $threshold) {
                return true;
            }
        }

        return false;
    }
}
