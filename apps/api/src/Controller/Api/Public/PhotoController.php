<?php

namespace App\Controller\Api\Public;

use App\Repository\PhotoRepository;
use App\Service\FaceSimilarityService;
use App\Service\PhotoPublicNormalizer;
use App\Service\ViewDeduplicatorInterface;
use App\Service\ViewVisitorIdentifier;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[AsController]
#[Route('/api/photos')]
class PhotoController
{
    public function __construct(
        private readonly PhotoRepository $photos,
        private readonly PhotoPublicNormalizer $normalizer,
        private readonly FaceSimilarityService $similarity,
        private readonly ViewDeduplicatorInterface $viewDeduplicator,
        private readonly ViewVisitorIdentifier $viewVisitor,
    ) {
    }

    #[Route('/{id}/similar', name: 'public_photos_similar', methods: ['GET'], priority: 10)]
    public function similar(string $id): JsonResponse
    {
        try {
            $uuid = Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Photo not found.');
        }

        $photo = $this->photos->findVisibleById($uuid);
        if (null === $photo) {
            throw new NotFoundHttpException('Photo not found.');
        }

        $similar = $this->similarity->findSimilarVisiblePhotos($photo);

        return new JsonResponse([
            'data' => array_map($this->normalizer->similar(...), $similar),
        ]);
    }

    #[Route('/{id}', name: 'public_photos_show', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        try {
            $uuid = Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Photo not found.');
        }

        $photo = $this->photos->findVisibleById($uuid);
        if (null === $photo) {
            throw new NotFoundHttpException('Photo not found.');
        }

        return new JsonResponse(['data' => $this->normalizer->detail($photo)]);
    }

    #[Route('/{id}/view', name: 'public_photos_record_view', methods: ['POST'])]
    public function recordView(string $id, Request $request): JsonResponse
    {
        try {
            $uuid = Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Photo not found.');
        }

        $photo = $this->photos->findVisibleById($uuid);
        if (null === $photo) {
            throw new NotFoundHttpException('Photo not found.');
        }

        $visitorId = $this->viewVisitor->resolve($request);
        if ($this->viewDeduplicator->claim('photo', (string) $photo->getId(), $visitorId)) {
            $photo->setViewCount($this->photos->incrementViewCount($photo->getId()));
        }

        $response = new JsonResponse(['data' => ['viewCount' => $photo->getViewCount()]]);
        $this->viewVisitor->attachCookie($request, $response, $visitorId);

        return $response;
    }
}
