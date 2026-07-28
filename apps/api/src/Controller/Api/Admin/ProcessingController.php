<?php

namespace App\Controller\Api\Admin;

use App\Entity\Photo;
use App\Enum\FacesStatus;
use App\Enum\MediaStatus;
use App\Enum\TagsStatus;
use App\Message\ConvertMediaMessage;
use App\Repository\PhotoRepository;
use App\Service\PhotoReprocessor;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[AsController]
#[Route('/api/admin/processing')]
final class ProcessingController
{
    private const REPROCESS_MAX_IDS = 100;
    private const ENQUEUE_IDS_MAX = 100;
    private const ENQUEUE_ALL_BATCH = 500;

    public function __construct(
        private readonly PhotoRepository $photos,
        private readonly PhotoReprocessor $reprocessor,
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[Route('/summary', name: 'admin_processing_summary', methods: ['GET'])]
    public function summary(): JsonResponse
    {
        return new JsonResponse(['data' => $this->photos->countGroupedByProcessingStatus()]);
    }

    #[Route('/photos', name: 'admin_processing_photos', methods: ['GET'])]
    public function photos(Request $request): JsonResponse
    {
        $stage = (string) $request->query->get('stage', 'media');
        $status = (string) $request->query->get('status', 'failed');
        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = min(100, max(1, (int) $request->query->get('perPage', 50)));

        $this->assertStageStatus($stage, $status);

        try {
            $result = $this->photos->findByProcessingStatus($stage, $status, $page, $perPage);
        } catch (\InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }

        return new JsonResponse([
            'data' => array_map($this->normalizePhoto(...), $result['items']),
            'meta' => [
                'page' => $page,
                'perPage' => $perPage,
                'total' => $result['total'],
            ],
        ]);
    }

    #[Route('/reprocess', name: 'admin_processing_reprocess', methods: ['POST'])]
    public function reprocess(Request $request): JsonResponse
    {
        $payload = $this->decode($request);
        $ids = $payload['photoIds'] ?? null;
        if (!\is_array($ids) || [] === $ids) {
            throw new BadRequestHttpException('"photoIds" must be a non-empty array.');
        }
        if (\count($ids) > self::REPROCESS_MAX_IDS) {
            throw new BadRequestHttpException(\sprintf('At most %d photoIds allowed.', self::REPROCESS_MAX_IDS));
        }

        $scope = $payload['scope'] ?? PhotoReprocessor::SCOPE_ALL;
        if (!\is_string($scope) || !\in_array($scope, PhotoReprocessor::SCOPES, true)) {
            throw new BadRequestHttpException('Invalid scope; expected all|faces|tags.');
        }

        $processed = 0;
        $skipped = 0;
        foreach ($ids as $id) {
            if (!\is_string($id) || !Uuid::isValid($id)) {
                ++$skipped;
                continue;
            }
            $photo = $this->photos->find($id);
            if (!$photo instanceof Photo) {
                ++$skipped;
                continue;
            }
            $this->reprocessor->reprocess($photo, $scope);
            ++$processed;
        }

        return new JsonResponse(['data' => ['processed' => $processed, 'skipped' => $skipped]]);
    }

    #[Route('/enqueue-convert', name: 'admin_processing_enqueue_convert', methods: ['POST'])]
    public function enqueueConvert(Request $request): JsonResponse
    {
        $payload = $this->decode($request);
        $allPending = !empty($payload['allPendingWithOriginal']);

        if ($allPending) {
            $totalEligible = $this->photos->countPendingWithOriginal();
            $eligible = $this->photos->findPendingWithOriginal(self::ENQUEUE_ALL_BATCH);
            $enqueued = $this->dispatchConvert($eligible);
            $remaining = max(0, $totalEligible - $enqueued);

            return new JsonResponse(['data' => ['enqueued' => $enqueued, 'remaining' => $remaining]]);
        }

        $ids = $payload['photoIds'] ?? null;
        if (!\is_array($ids) || [] === $ids) {
            throw new BadRequestHttpException('Provide "photoIds" or set "allPendingWithOriginal": true.');
        }
        if (\count($ids) > self::ENQUEUE_IDS_MAX) {
            throw new BadRequestHttpException(\sprintf('At most %d photoIds allowed.', self::ENQUEUE_IDS_MAX));
        }

        $photos = [];
        foreach ($ids as $id) {
            if (!\is_string($id) || !Uuid::isValid($id)) {
                continue;
            }
            $photo = $this->photos->find($id);
            if ($photo instanceof Photo && $this->isEligibleForConvert($photo)) {
                $photos[] = $photo;
            }
        }

        $enqueued = $this->dispatchConvert($photos);

        return new JsonResponse(['data' => ['enqueued' => $enqueued, 'remaining' => 0]]);
    }

    /**
     * @param list<Photo> $photos
     */
    private function dispatchConvert(array $photos): int
    {
        $n = 0;
        foreach ($photos as $photo) {
            $this->bus->dispatch(new ConvertMediaMessage((string) $photo->getId()));
            ++$n;
        }

        return $n;
    }

    private function isEligibleForConvert(Photo $photo): bool
    {
        if (MediaStatus::Pending !== $photo->getMediaStatus()) {
            return false;
        }
        $path = $photo->getOriginalPath();

        return null !== $path && '' !== $path;
    }

    private function assertStageStatus(string $stage, string $status): void
    {
        $valid = match ($stage) {
            'media' => array_map(static fn (MediaStatus $c) => $c->value, MediaStatus::cases()),
            'faces' => array_map(static fn (FacesStatus $c) => $c->value, FacesStatus::cases()),
            'tags' => array_map(static fn (TagsStatus $c) => $c->value, TagsStatus::cases()),
            default => null,
        };
        if (null === $valid) {
            throw new BadRequestHttpException('Invalid stage; expected media|faces|tags.');
        }
        if (!\in_array($status, $valid, true)) {
            throw new BadRequestHttpException(\sprintf('Invalid status "%s" for stage "%s".', $status, $stage));
        }
    }

    /** @return array<string, mixed> */
    private function normalizePhoto(Photo $photo): array
    {
        $original = $photo->getOriginalPath();

        return [
            'id' => (string) $photo->getId(),
            'title' => $photo->getTitle(),
            'albumId' => (string) $photo->getAlbum()->getId(),
            'albumTitle' => $photo->getAlbum()->getTitle(),
            'mediaStatus' => $photo->getMediaStatus()->value,
            'facesStatus' => $photo->getFacesStatus()->value,
            'tagsStatus' => $photo->getTagsStatus()->value,
            'processingError' => $photo->getProcessingError(),
            'hasOriginal' => null !== $original && '' !== $original,
            'avifPath' => $photo->getAvifPath(),
            'thumbPaths' => $photo->getThumbPaths(),
            'originalPath' => $original,
        ];
    }

    /** @return array<string, mixed> */
    private function decode(Request $request): array
    {
        try {
            return $request->toArray();
        } catch (\JsonException) {
            throw new BadRequestHttpException('Invalid JSON body.');
        }
    }
}
