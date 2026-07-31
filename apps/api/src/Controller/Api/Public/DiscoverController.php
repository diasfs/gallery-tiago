<?php

namespace App\Controller\Api\Public;

use App\Entity\Album;
use App\Entity\Photo;
use App\Http\Pagination;
use App\Repository\AlbumRepository;
use App\Repository\PhotoRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
#[Route('/api/discover')]
class DiscoverController
{
    private const DEFAULT_PER_PAGE = 48;

    public function __construct(
        private readonly PhotoRepository $photos,
        private readonly AlbumRepository $albums,
    ) {
    }

    #[Route('/on-this-day', name: 'public_discover_on_this_day', methods: ['GET'])]
    public function onThisDay(Request $request): JsonResponse
    {
        [$month, $day, $beforeYear] = $this->resolveOnThisDayParams($request);
        $page = Pagination::page($request);
        $perPage = Pagination::perPage($request, self::DEFAULT_PER_PAGE);
        $result = $this->photos->findPublicOnThisDayPaginated($month, $day, $beforeYear, $page, $perPage);

        return new JsonResponse([
            'data' => array_map($this->normalizeOnThisDayPhoto(...), $result['items']),
            'meta' => [
                ...Pagination::meta($page, $perPage, $result['total']),
                'month' => $month,
                'day' => $day,
                'beforeYear' => $beforeYear,
            ],
        ]);
    }

    #[Route('/most-viewed/photos', name: 'public_discover_most_viewed_photos', methods: ['GET'])]
    public function mostViewedPhotos(Request $request): JsonResponse
    {
        $page = Pagination::page($request);
        $perPage = Pagination::perPage($request, self::DEFAULT_PER_PAGE);
        $result = $this->photos->findPublicMostViewedPaginated($page, $perPage);

        return new JsonResponse([
            'data' => array_map($this->normalizePhoto(...), $result['items']),
            'meta' => [
                ...Pagination::meta($page, $perPage, $result['total']),
                'period' => 'all',
            ],
        ]);
    }

    #[Route('/most-viewed/albums', name: 'public_discover_most_viewed_albums', methods: ['GET'])]
    public function mostViewedAlbums(Request $request): JsonResponse
    {
        $page = Pagination::page($request);
        $perPage = Pagination::perPage($request, self::DEFAULT_PER_PAGE);
        $result = $this->albums->findPublicMostViewedPaginated($page, $perPage);

        return new JsonResponse([
            'data' => array_map($this->normalizeAlbum(...), $result['items']),
            'meta' => [
                ...Pagination::meta($page, $perPage, $result['total']),
                'period' => 'all',
            ],
        ]);
    }

    /** @return array{0: int, 1: int, 2: int} */
    private function resolveOnThisDayParams(Request $request): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $month = (int) $request->query->get('month', $now->format('n'));
        $day = (int) $request->query->get('day', $now->format('j'));
        $beforeYear = (int) $request->query->get('beforeYear', $now->format('Y'));

        if ($month < 1 || $month > 12 || $day < 1 || $day > 31 || $beforeYear < 1) {
            throw new BadRequestHttpException('Invalid on-this-day parameters.');
        }

        if (!checkdate($month, $day, $beforeYear)) {
            throw new BadRequestHttpException('Invalid month/day combination.');
        }

        return [$month, $day, $beforeYear];
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

    /** @return array<string, mixed> */
    private function normalizeOnThisDayPhoto(Photo $photo): array
    {
        $timelineAt = $this->photoTimelineAt($photo);

        return [
            ...$this->normalizePhoto($photo),
            'timelineAt' => $timelineAt->format(\DATE_ATOM),
            'yearsAgo' => max(0, (int) (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y') - (int) $timelineAt->format('Y')),
        ];
    }

    private function photoTimelineAt(Photo $photo): \DateTimeImmutable
    {
        return $photo->getAlbum()->getTakenAt()
            ?? $photo->getAlbum()->getCreatedAt()
            ?? $photo->getCreatedAt();
    }

    /** @return array<string, mixed> */
    private function normalizeAlbum(Album $album): array
    {
        $cover = $album->getCoverPhoto();

        return [
            'id' => (string) $album->getId(),
            'slug' => $album->getSlug(),
            'title' => $album->getTitle(),
            'description' => $album->getDescription(),
            'takenAt' => $album->getTakenAt()?->format(\DATE_ATOM),
            'takenAtEnd' => $album->getTakenAtEnd()?->format(\DATE_ATOM),
            'viewCount' => $album->getViewCount(),
            'coverPhoto' => null !== $cover ? [
                'id' => (string) $cover->getId(),
                'title' => $cover->getTitle(),
                'avifPath' => $cover->getAvifPath(),
                'thumbPaths' => $cover->getThumbPaths(),
                'originalPath' => $cover->getOriginalPath(),
            ] : null,
        ];
    }
}
