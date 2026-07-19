<?php

namespace App\Controller\Api\Public;

use App\Entity\Album;
use App\Repository\AlbumRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
#[Route('/api/albums')]
class AlbumController
{
    public function __construct(private readonly AlbumRepository $albums)
    {
    }

    #[Route('', name: 'public_albums_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $albums = array_map(
            $this->normalize(...),
            $this->albums->findPublicRoots(),
        );

        return new JsonResponse(['data' => $albums]);
    }

    #[Route('/{slug}', name: 'public_albums_show', methods: ['GET'])]
    public function show(string $slug): JsonResponse
    {
        $album = $this->albums->findVisibleBySlug($slug);
        if (null === $album) {
            throw new NotFoundHttpException('Album not found.');
        }

        return new JsonResponse(['data' => $this->normalize($album)]);
    }

    private function normalize(Album $album): array
    {
        return [
            'id' => (string) $album->getId(),
            'title' => $album->getTitle(),
            'slug' => $album->getSlug(),
            'description' => $album->getDescription(),
            'visibility' => $album->getVisibility()->value,
            'sortOrder' => $album->getSortOrder(),
            'coverPhotoId' => $album->getCoverPhoto()?->getId()->toRfc4122(),
            'parentSlug' => $album->getParent()?->getSlug(),
        ];
    }
}
