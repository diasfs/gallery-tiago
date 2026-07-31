<?php

namespace App\Controller\Api\Public;

use App\Entity\Album;
use App\Entity\Location;
use App\Entity\Photo;
use App\Enum\AlbumVisibility;
use App\Http\Pagination;
use App\Repository\AlbumRepository;
use App\Repository\PhotoRepository;
use App\Service\PhotoPublicNormalizer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[AsController]
#[Route('/api/search')]
class SearchController
{
    private const DEFAULT_ALBUM_PER_PAGE = 24;
    private const DEFAULT_PHOTO_PER_PAGE = 48;

    public function __construct(
        private readonly AlbumRepository $albums,
        private readonly PhotoRepository $photos,
        private readonly PhotoPublicNormalizer $photoNormalizer,
    ) {
    }

    #[Route('', name: 'public_search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $filters = $this->filtersFromRequest($request);
        $albumPage = max(1, (int) $request->query->get('albumPage', 1));
        $photoPage = max(1, (int) $request->query->get('photoPage', 1));
        $albumPerPage = min(100, max(1, (int) $request->query->get('albumPerPage', self::DEFAULT_ALBUM_PER_PAGE)));
        $photoPerPage = min(100, max(1, (int) $request->query->get('photoPerPage', self::DEFAULT_PHOTO_PER_PAGE)));

        if (!$this->hasCriteria($filters)) {
            return new JsonResponse([
                'data' => ['albums' => [], 'photos' => []],
                'meta' => [
                    'albums' => Pagination::meta($albumPage, $albumPerPage, 0),
                    'photos' => Pagination::meta($photoPage, $photoPerPage, 0),
                ],
            ]);
        }

        $albumResult = $this->albums->searchPublicPaginated($albumPage, $albumPerPage, $filters);
        $photoResult = $this->photos->searchPublicPaginated($photoPage, $photoPerPage, $filters);

        return new JsonResponse([
            'data' => [
                'albums' => array_map($this->normalizeAlbum(...), $albumResult['items']),
                'photos' => array_map($this->photoNormalizer->summary(...), $photoResult['items']),
            ],
            'meta' => [
                'albums' => Pagination::meta($albumPage, $albumPerPage, $albumResult['total']),
                'photos' => Pagination::meta($photoPage, $photoPerPage, $photoResult['total']),
            ],
        ]);
    }

    /**
     * @return array{
     *   q: string|null,
     *   personIds: list<string>,
     *   tagSlugs: list<string>,
     *   year: int|null,
     *   from: string|null,
     *   to: string|null
     * }
     */
    private function filtersFromRequest(Request $request): array
    {
        $q = $request->query->get('q');
        $q = \is_string($q) ? trim($q) : null;
        if ('' === $q) {
            $q = null;
        }

        $personIds = [];
        $rawPeople = $request->query->all()['person'] ?? [];
        if (\is_string($rawPeople)) {
            $rawPeople = [$rawPeople];
        }
        foreach ($rawPeople as $raw) {
            if (!\is_string($raw) || '' === $raw) {
                continue;
            }
            try {
                $personIds[] = (string) Uuid::fromString($raw);
            } catch (\InvalidArgumentException) {
                continue;
            }
        }

        $tagSlugs = [];
        $rawTags = $request->query->all()['tag'] ?? [];
        if (\is_string($rawTags)) {
            $rawTags = [$rawTags];
        }
        foreach ($rawTags as $raw) {
            if (\is_string($raw) && '' !== trim($raw)) {
                $tagSlugs[] = trim($raw);
            }
        }

        $year = null;
        $yearRaw = $request->query->get('year');
        if (null !== $yearRaw && '' !== $yearRaw && preg_match('/^\d{4}$/', (string) $yearRaw)) {
            $year = (int) $yearRaw;
        }

        $from = null;
        $to = null;
        if (null === $year) {
            $fromRaw = $request->query->get('from');
            $toRaw = $request->query->get('to');
            if (\is_string($fromRaw) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromRaw)) {
                $from = $fromRaw;
            }
            if (\is_string($toRaw) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $toRaw)) {
                $to = $toRaw;
            }
        }

        return [
            'q' => $q,
            'personIds' => array_values(array_unique($personIds)),
            'tagSlugs' => array_values(array_unique($tagSlugs)),
            'year' => $year,
            'from' => $from,
            'to' => $to,
        ];
    }

    /** @param array{q: string|null, personIds: list<string>, tagSlugs: list<string>, year: int|null, from: string|null, to: string|null} $filters */
    private function hasCriteria(array $filters): bool
    {
        return null !== $filters['q']
            || [] !== $filters['personIds']
            || [] !== $filters['tagSlugs']
            || null !== $filters['year']
            || null !== $filters['from']
            || null !== $filters['to'];
    }

    /** @return array<string, mixed> */
    private function normalizeAlbum(Album $album): array
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
            'parentSlug' => $this->isVisible($album->getParent()) ? $album->getParent()->getSlug() : null,
            'takenAt' => $album->getTakenAt()?->format(\DATE_ATOM),
            'takenAtEnd' => $album->getTakenAtEnd()?->format(\DATE_ATOM),
            'location' => $this->normalizeLocation($album->getLocation()),
            'viewCount' => $album->getViewCount(),
        ];
    }

    /**
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

    private function isVisible(?Album $album): bool
    {
        return null !== $album && AlbumVisibility::Private !== $album->getVisibility();
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
