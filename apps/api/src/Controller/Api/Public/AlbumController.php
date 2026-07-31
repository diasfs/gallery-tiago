<?php

namespace App\Controller\Api\Public;

use App\Entity\Album;
use App\Entity\Location;
use App\Entity\Photo;
use App\Enum\AlbumVisibility;
use App\Http\Pagination;
use App\Repository\AlbumRepository;
use App\Repository\PhotoRepository;
use App\Service\ViewDeduplicatorInterface;
use App\Service\ViewVisitorIdentifier;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
#[Route('/api/albums')]
class AlbumController
{
    private const DEFAULT_ALBUM_PER_PAGE = 24;
    private const DEFAULT_RECENT_LIMIT = 12;
    private const MAX_RECENT_LIMIT = 48;

    public function __construct(
        private readonly AlbumRepository $albums,
        private readonly PhotoRepository $photos,
        private readonly ViewDeduplicatorInterface $viewDeduplicator,
        private readonly ViewVisitorIdentifier $viewVisitor,
    ) {
    }

    #[Route('', name: 'public_albums_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $page = Pagination::page($request);
        $perPage = Pagination::perPage($request, self::DEFAULT_ALBUM_PER_PAGE);
        $result = $this->albums->findPublicRootsPaginated($page, $perPage);

        return new JsonResponse([
            'data' => array_map($this->normalize(...), $result['items']),
            'meta' => Pagination::meta($page, $perPage, $result['total']),
        ]);
    }

    #[Route('/recent', name: 'public_albums_recent', methods: ['GET'])]
    public function recent(Request $request): JsonResponse
    {
        $limit = $this->recentLimit($request);
        $items = $this->albums->findPublicRecent($limit);

        return new JsonResponse([
            'data' => array_map($this->normalize(...), $items),
            'meta' => ['limit' => $limit],
        ]);
    }

    #[Route('/map', name: 'public_albums_map', methods: ['GET'])]
    public function map(): JsonResponse
    {
        $items = $this->albums->findPublicWithCoordinates();

        return new JsonResponse([
            'data' => array_map($this->normalize(...), $items),
        ]);
    }

    #[Route('/{slug}/children', name: 'public_albums_children', methods: ['GET'])]
    public function children(string $slug, Request $request): JsonResponse
    {
        $album = $this->findVisibleOrFail($slug);
        $page = Pagination::page($request);
        $perPage = Pagination::perPage($request, self::DEFAULT_ALBUM_PER_PAGE);
        $result = $this->albums->findVisibleChildrenPaginated($album, $page, $perPage);

        return new JsonResponse([
            'data' => array_map($this->normalize(...), $result['items']),
            'meta' => Pagination::meta($page, $perPage, $result['total']),
        ]);
    }

    #[Route('/{slug}/photos', name: 'public_albums_photos', methods: ['GET'])]
    public function photos(string $slug, Request $request): JsonResponse
    {
        $album = $this->findVisibleOrFail($slug);
        $page = Pagination::page($request);
        // Authoritative album setting — ignore visitor-supplied perPage.
        $perPage = max(1, $album->getPhotosPerPage());
        $result = $this->photos->findByAlbumPaginated($album, $page, $perPage);

        return new JsonResponse([
            'data' => array_map($this->normalizePhoto(...), $result['items']),
            'meta' => Pagination::meta($page, $perPage, $result['total']),
        ]);
    }

    #[Route('/{slug}', name: 'public_albums_show', methods: ['GET'])]
    public function show(string $slug): JsonResponse
    {
        $album = $this->findVisibleOrFail($slug);

        return new JsonResponse(['data' => $this->normalizeDetail($album)]);
    }

    #[Route('/{slug}/view', name: 'public_albums_record_view', methods: ['POST'])]
    public function recordView(string $slug, Request $request): JsonResponse
    {
        $album = $this->findVisibleOrFail($slug);
        $visitorId = $this->viewVisitor->resolve($request);

        if ($this->viewDeduplicator->claim('album', (string) $album->getId(), $visitorId)) {
            $album->setViewCount($this->albums->incrementViewCount($album->getId()));
        }

        $response = new JsonResponse(['data' => ['viewCount' => $album->getViewCount()]]);
        $this->viewVisitor->attachCookie($request, $response, $visitorId);

        return $response;
    }

    private function findVisibleOrFail(string $slug): Album
    {
        $album = $this->albums->findVisibleBySlug($slug);
        if (null === $album) {
            throw new NotFoundHttpException('Album not found.');
        }

        return $album;
    }

    private function recentLimit(Request $request): int
    {
        $raw = $request->query->get('limit', self::DEFAULT_RECENT_LIMIT);
        if (!is_numeric($raw)) {
            return self::DEFAULT_RECENT_LIMIT;
        }

        return max(1, min(self::MAX_RECENT_LIMIT, (int) $raw));
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
            'coverPhoto' => $this->normalizeCoverPhoto($album->getCoverPhoto()),
            // Never expose a private parent's slug (same rule as `ancestors()`).
            'parentSlug' => $this->isVisible($album->getParent()) ? $album->getParent()->getSlug() : null,
            'takenAt' => $album->getTakenAt()?->format(\DATE_ATOM),
            'takenAtEnd' => $album->getTakenAtEnd()?->format(\DATE_ATOM),
            'location' => $this->normalizeLocation($album->getLocation()),
            'viewCount' => $album->getViewCount(),
        ];
    }

    /** @return array<string, mixed> */
    private function normalizeDetail(Album $album): array
    {
        return $this->normalize($album) + [
            'ancestors' => $this->ancestors($album),
            'photosPerPage' => $album->getPhotosPerPage(),
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
            'avifPath' => $photo->getAvifPath(),
            'thumbPaths' => $photo->getThumbPaths(),
            'originalPath' => $photo->getOriginalPath(),
            'viewCount' => $photo->getViewCount(),
        ];
    }

    /**
     * Media paths for album-card covers — never triggers a photo detail fetch.
     *
     * @return array{id: string, avifPath: ?string, thumbPaths: array, originalPath: ?string}|null
     */
    private function normalizeCoverPhoto(?Photo $photo): ?array
    {
        if (null === $photo) {
            return null;
        }

        return [
            'id' => (string) $photo->getId(),
            'avifPath' => $photo->getAvifPath(),
            'thumbPaths' => $photo->getThumbPaths(),
            'originalPath' => $photo->getOriginalPath(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function normalizeLocation(?Location $location): ?array
    {
        if (null === $location) {
            return null;
        }

        return [
            'id' => (string) $location->getId(),
            'name' => $location->getName(),
            'city' => $location->getCity(),
            'country' => $location->getCountry(),
            'latitude' => $location->getLatitude(),
            'longitude' => $location->getLongitude(),
        ];
    }
}
