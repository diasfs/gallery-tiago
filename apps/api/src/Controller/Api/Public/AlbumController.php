<?php

namespace App\Controller\Api\Public;

use App\Entity\Album;
use App\Entity\Photo;
use App\Enum\AlbumVisibility;
use App\Repository\AlbumRepository;
use App\Repository\PhotoRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
#[Route('/api/albums')]
class AlbumController
{
    public function __construct(
        private readonly AlbumRepository $albums,
        private readonly PhotoRepository $photos,
    ) {
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

        return new JsonResponse(['data' => $this->normalizeDetail($album)]);
    }

    /** @return array<string, mixed> */
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
            // Never expose a private parent's slug (same rule as `ancestors()`).
            'parentSlug' => $this->isVisible($album->getParent()) ? $album->getParent()->getSlug() : null,
        ];
    }

    /** @return array<string, mixed> */
    private function normalizeDetail(Album $album): array
    {
        return $this->normalize($album) + [
            'ancestors' => $this->ancestors($album),
            'children' => array_map($this->normalize(...), $this->albums->findVisibleChildren($album)),
            'photos' => array_map($this->normalizePhoto(...), $this->photos->findByAlbum($album)),
        ];
    }

    /** @return array<int, array{slug: string, title: string}> */
    private function ancestors(Album $album): array
    {
        $chain = [];
        $current = $album->getParent();
        while ($this->isVisible($current)) {
            $chain[] = ['slug' => $current->getSlug(), 'title' => $current->getTitle()];
            $current = $current->getParent();
        }

        return array_reverse($chain);
    }

    /**
     * True unless `$album` is null or `Private` — used to stop walking the
     * ancestor chain and to omit `parentSlug` once a private ancestor is
     * hit, so private album titles/slugs never leak into public payloads.
     */
    private function isVisible(?Album $album): bool
    {
        return null !== $album && AlbumVisibility::Private !== $album->getVisibility();
    }

    /** @return array<string, mixed> */
    private function normalizePhoto(Photo $photo): array
    {
        return [
            'id' => (string) $photo->getId(),
            'title' => $photo->getTitle(),
            'takenAt' => $photo->getTakenAt()?->format(\DATE_ATOM),
            'avifPath' => $photo->getAvifPath(),
            'thumbPaths' => $photo->getThumbPaths(),
        ];
    }
}
