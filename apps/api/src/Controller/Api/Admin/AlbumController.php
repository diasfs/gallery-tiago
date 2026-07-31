<?php

namespace App\Controller\Api\Admin;

use App\Entity\Album;
use App\Entity\Location;
use App\Entity\Photo;
use App\Enum\AlbumVisibility;
use App\Http\Pagination;
use App\Repository\AlbumRepository;
use App\Repository\LocationRepository;
use App\Repository\PhotoRepository;
use App\Support\ReservedAlbumSlugs;
use App\Service\AlbumDeleter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[AsController]
#[Route('/api/admin/albums')]
class AlbumController
{
    private const DEFAULT_ALBUM_PER_PAGE = 24;

    public function __construct(
        private readonly AlbumRepository $albums,
        private readonly PhotoRepository $photos,
        private readonly LocationRepository $locations,
        private readonly EntityManagerInterface $em,
        private readonly AlbumDeleter $albumDeleter,
    ) {
    }

    #[Route('/parent-options', name: 'admin_albums_parent_options', methods: ['GET'])]
    public function parentOptions(Request $request): JsonResponse
    {
        $page = Pagination::page($request);
        $perPage = Pagination::perPage($request, 20);
        $q = $request->query->get('q');
        $q = \is_string($q) && '' !== trim($q) ? trim($q) : null;

        $excludeIds = [];
        $exclude = $request->query->get('exclude');
        if (\is_string($exclude) && '' !== $exclude) {
            $album = $this->findOrFail($exclude);
            $excludeIds[] = (string) $album->getId();
            $excludeIds = array_merge($excludeIds, $this->albums->findDescendantIds($album));
        }

        $result = $this->albums->findParentOptionsPaginated($page, $perPage, $q, $excludeIds);
        $data = [];
        foreach ($result['items'] as $album) {
            $data[] = [
                'id' => (string) $album->getId(),
                'title' => $album->getTitle(),
                'parentId' => $album->getParent()?->getId()->toRfc4122(),
            ];
        }

        return new JsonResponse([
            'data' => $data,
            'meta' => Pagination::meta($page, $perPage, $result['total']),
        ]);
    }

    /**
     * Replaces root-album order. `albumIds` must list every root album.
     */
    #[Route('/order', name: 'admin_albums_order', methods: ['PUT'])]
    public function reorderRoots(Request $request): JsonResponse
    {
        $orderedIds = $this->parseAlbumIds($request);
        $albums = $this->albums->findRootsOrdered();
        $this->applyReorder($albums, $orderedIds);

        $ids = array_map(static fn (Album $album): string => (string) $album->getId(), $albums);
        $photoCounts = $this->albums->countPhotosForAlbumIds($ids);
        $childCounts = $this->albums->countChildrenForParentIds($ids);

        $data = [];
        foreach ($this->albums->findRootsOrdered() as $album) {
            $id = (string) $album->getId();
            $data[] = $this->normalize(
                $album,
                photoCount: $photoCounts[$id] ?? 0,
                childCount: $childCounts[$id] ?? 0,
            );
        }

        return new JsonResponse(['data' => $data]);
    }

    #[Route('', name: 'admin_albums_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $page = Pagination::page($request);
        $perPage = Pagination::perPage($request, self::DEFAULT_ALBUM_PER_PAGE);
        $filters = [
            'visibility' => $request->query->get('visibility'),
            'q' => $request->query->get('q'),
            'from' => $request->query->get('from'),
            'to' => $request->query->get('to'),
            'location' => $request->query->get('location'),
        ];

        $result = $this->albums->findRootsPaginated($page, $perPage, $filters);
        $ids = array_map(
            static fn (Album $album): string => (string) $album->getId(),
            $result['items'],
        );
        $photoCounts = $this->albums->countPhotosForAlbumIds($ids);
        $childCounts = $this->albums->countChildrenForParentIds($ids);

        $data = [];
        foreach ($result['items'] as $album) {
            $id = (string) $album->getId();
            $data[] = $this->normalize(
                $album,
                photoCount: $photoCounts[$id] ?? 0,
                childCount: $childCounts[$id] ?? 0,
            );
        }

        return new JsonResponse([
            'data' => $data,
            'meta' => Pagination::meta($page, $perPage, $result['total']),
        ]);
    }

    #[Route('', name: 'admin_albums_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $payload = $this->decode($request);

        $title = $payload['title'] ?? null;
        $slug = $payload['slug'] ?? null;
        if (!\is_string($title) || '' === $title || !\is_string($slug) || '' === $slug) {
            throw new BadRequestHttpException('title and slug are required.');
        }
        $this->assertSlugAllowed($slug);

        $album = new Album($title, $slug);
        $this->applyPayload($album, $payload);

        $this->em->persist($album);
        $this->em->flush();

        return new JsonResponse(['data' => $this->normalize($album)], Response::HTTP_CREATED);
    }

    #[Route('/{id}/children', name: 'admin_albums_children', methods: ['GET'])]
    public function children(string $id, Request $request): JsonResponse
    {
        $album = $this->findOrFail($id);
        $page = Pagination::page($request);
        $perPage = Pagination::perPage($request, self::DEFAULT_ALBUM_PER_PAGE);
        $result = $this->albums->findChildrenPaginated($album, $page, $perPage);
        $ids = array_map(
            static fn (Album $child): string => (string) $child->getId(),
            $result['items'],
        );
        $photoCounts = $this->albums->countPhotosForAlbumIds($ids);
        $childCounts = $this->albums->countChildrenForParentIds($ids);

        $data = [];
        foreach ($result['items'] as $child) {
            $childId = (string) $child->getId();
            $data[] = $this->normalize(
                $child,
                photoCount: $photoCounts[$childId] ?? 0,
                childCount: $childCounts[$childId] ?? 0,
            );
        }

        return new JsonResponse([
            'data' => $data,
            'meta' => Pagination::meta($page, $perPage, $result['total']),
        ]);
    }

    #[Route('/{id}', name: 'admin_albums_show', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        $album = $this->findOrFail($id);
        $idStr = (string) $album->getId();
        $photoCounts = $this->albums->countPhotosForAlbumIds([$idStr]);
        $childCounts = $this->albums->countChildrenForParentIds([$idStr]);

        $payload = $this->normalize(
            $album,
            photoCount: $photoCounts[$idStr] ?? 0,
            childCount: $childCounts[$idStr] ?? 0,
        );
        $parent = $album->getParent();
        $payload['parent'] = null === $parent
            ? null
            : [
                'id' => (string) $parent->getId(),
                'title' => $parent->getTitle(),
            ];

        return new JsonResponse(['data' => $payload]);
    }

    #[Route('/{id}', name: 'admin_albums_update', methods: ['PATCH'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $album = $this->findOrFail($id);
        $payload = $this->decode($request);

        if (isset($payload['title'])) {
            if (!\is_string($payload['title']) || '' === $payload['title']) {
                throw new BadRequestHttpException('title must be a non-empty string.');
            }
            $album->setTitle($payload['title']);
        }
        if (isset($payload['slug'])) {
            if (!\is_string($payload['slug']) || '' === $payload['slug']) {
                throw new BadRequestHttpException('slug must be a non-empty string.');
            }
            $this->assertSlugAllowed($payload['slug']);
            $album->setSlug($payload['slug']);
        }

        $this->applyPayload($album, $payload);
        $album->touch();
        $this->em->flush();

        return new JsonResponse(['data' => $this->normalize($album)]);
    }

    #[Route('/{id}', name: 'admin_albums_delete', methods: ['DELETE'])]
    public function delete(string $id): Response
    {
        $album = $this->findOrFail($id);
        $this->albumDeleter->delete($album);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    private function findOrFail(string $id): Album
    {
        try {
            $uuid = Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Album not found.');
        }

        $album = $this->albums->find($uuid);
        if (null === $album) {
            throw new NotFoundHttpException('Album not found.');
        }

        return $album;
    }

    /** @param array<string, mixed> $payload */
    private function applyPayload(Album $album, array $payload): void
    {
        if (isset($payload['description'])) {
            $album->setDescription(\is_string($payload['description']) ? $payload['description'] : null);
        }

        if (isset($payload['visibility'])) {
            $visibility = AlbumVisibility::tryFrom((string) $payload['visibility']);
            if (null === $visibility) {
                throw new BadRequestHttpException('visibility must be one of: public, unlisted, private.');
            }
            $album->setVisibility($visibility);
        }

        if (isset($payload['sortOrder'])) {
            if (!\is_int($payload['sortOrder'])) {
                throw new BadRequestHttpException('sortOrder must be an integer.');
            }
            $album->setSortOrder($payload['sortOrder']);
        }

        if (\array_key_exists('photosPerPage', $payload)) {
            if (!\is_int($payload['photosPerPage']) || $payload['photosPerPage'] < 1) {
                throw new BadRequestHttpException('photosPerPage must be an integer greater than or equal to 1.');
            }
            $album->setPhotosPerPage($payload['photosPerPage']);
        }

        if (\array_key_exists('parentId', $payload)) {
            if (null === $payload['parentId']) {
                $album->setParent(null);
            } else {
                $parent = $this->findOrFail((string) $payload['parentId']);
                $album->setParent($parent);
            }
            $this->assertValidParent($album);
        }

        if (\array_key_exists('coverPhotoId', $payload)) {
            $album->setCoverPhoto($this->resolveCoverPhoto($payload['coverPhotoId']));
        }

        if (\array_key_exists('takenAt', $payload)) {
            $album->setTakenAt($this->parseTakenAt($payload['takenAt'], 'takenAt'));
        }

        if (\array_key_exists('takenAtEnd', $payload)) {
            $album->setTakenAtEnd($this->parseTakenAt($payload['takenAtEnd'], 'takenAtEnd'));
        }

        $this->assertTakenAtRange($album);

        if (\array_key_exists('locationId', $payload)) {
            $album->setLocation($this->resolveLocation($payload['locationId']));
        }
    }

    private function assertValidParent(Album $album): void
    {
        $parent = $album->getParent();
        if (null === $parent) {
            return;
        }

        if ($parent->getId()->equals($album->getId())) {
            throw new BadRequestHttpException('An album cannot be its own parent.');
        }

        $ancestor = $parent->getParent();
        while (null !== $ancestor) {
            if ($ancestor->getId()->equals($album->getId())) {
                throw new BadRequestHttpException('An album cannot be moved under its own descendant.');
            }
            $ancestor = $ancestor->getParent();
        }
    }

    private function assertTakenAtRange(Album $album): void
    {
        $start = $album->getTakenAt();
        $end = $album->getTakenAtEnd();
        if (null !== $end && null === $start) {
            throw new BadRequestHttpException('takenAt is required when takenAtEnd is set.');
        }
        if (null !== $start && null !== $end && $end < $start) {
            throw new BadRequestHttpException('takenAtEnd must be on or after takenAt.');
        }
    }

    private function parseTakenAt(mixed $value, string $field = 'takenAt'): ?\DateTimeImmutable
    {
        if (null === $value) {
            return null;
        }
        if (!\is_string($value)) {
            throw new BadRequestHttpException($field.' must be an ISO-8601 date string or null.');
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            throw new BadRequestHttpException($field.' must be a valid date string.');
        }
    }

    private function resolveLocation(mixed $value): ?Location
    {
        if (null === $value) {
            return null;
        }
        if (!\is_string($value)) {
            throw new BadRequestHttpException('locationId must be a string or null.');
        }

        try {
            $uuid = Uuid::fromString($value);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Location not found.');
        }

        $location = $this->locations->find($uuid);
        if (null === $location) {
            throw new NotFoundHttpException('Location not found.');
        }

        return $location;
    }

    private function resolveCoverPhoto(mixed $value): ?Photo
    {
        if (null === $value) {
            return null;
        }
        if (!\is_string($value)) {
            throw new BadRequestHttpException('coverPhotoId must be a string or null.');
        }

        try {
            $uuid = Uuid::fromString($value);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Photo not found.');
        }

        $photo = $this->photos->find($uuid);
        if (null === $photo) {
            throw new NotFoundHttpException('Photo not found.');
        }

        return $photo;
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

    /** @return array<string, mixed> */
    private function decode(Request $request): array
    {
        try {
            return $request->toArray();
        } catch (\JsonException) {
            throw new BadRequestHttpException('Invalid JSON body.');
        }
    }

    /** @return list<string> */
    private function parseAlbumIds(Request $request): array
    {
        $payload = $this->decode($request);
        $albumIds = $payload['albumIds'] ?? null;
        if (!\is_array($albumIds) || [] === $albumIds) {
            throw new BadRequestHttpException('albumIds must be a non-empty array of album ids.');
        }

        $ordered = [];
        foreach ($albumIds as $id) {
            if (!\is_string($id) || '' === $id) {
                throw new BadRequestHttpException('albumIds must contain only non-empty strings.');
            }
            $ordered[] = $id;
        }

        if (\count($ordered) !== \count(array_unique($ordered))) {
            throw new BadRequestHttpException('albumIds must not contain duplicates.');
        }

        return $ordered;
    }

    /**
     * @param Album[]  $albums
     * @param string[] $orderedIds
     */
    private function applyReorder(array $albums, array $orderedIds, ?Album $expectedParent = null): void
    {
        if (\count($orderedIds) !== \count($albums)) {
            throw new BadRequestHttpException('albumIds must include every album exactly once.');
        }

        $byId = [];
        foreach ($albums as $album) {
            $parentId = $album->getParent()?->getId()->toRfc4122();
            $expectedParentId = $expectedParent?->getId()->toRfc4122();
            if ($parentId !== $expectedParentId) {
                throw new BadRequestHttpException('albumIds contains an album outside this scope.');
            }
            $byId[(string) $album->getId()] = $album;
        }

        foreach ($orderedIds as $id) {
            if (!isset($byId[$id])) {
                throw new BadRequestHttpException('albumIds contains an album that does not belong to this scope.');
            }
        }

        foreach ($orderedIds as $index => $id) {
            $byId[$id]->setSortOrder($index);
        }
        $this->em->flush();
    }

    private function normalize(Album $album, ?int $photoCount = null, ?int $childCount = null): array
    {
        return [
            'id' => (string) $album->getId(),
            'title' => $album->getTitle(),
            'slug' => $album->getSlug(),
            'description' => $album->getDescription(),
            'visibility' => $album->getVisibility()->value,
            'sortOrder' => $album->getSortOrder(),
            'photosPerPage' => $album->getPhotosPerPage(),
            'coverPhotoId' => $album->getCoverPhoto()?->getId()->toRfc4122(),
            'cover' => $this->normalizeCover($album->getCoverPhoto()),
            'parentId' => $album->getParent()?->getId()->toRfc4122(),
            'childCount' => $childCount ?? $album->getChildren()->count(),
            'photoCount' => $photoCount ?? $album->getPhotos()->count(),
            'takenAt' => $album->getTakenAt()?->format(\DATE_ATOM),
            'takenAtEnd' => $album->getTakenAtEnd()?->format(\DATE_ATOM),
            'location' => $this->normalizeLocation($album->getLocation()),
            'createdAt' => $album->getCreatedAt()->format(\DATE_ATOM),
            'updatedAt' => $album->getUpdatedAt()->format(\DATE_ATOM),
        ];
    }

    /** @return array{id: string, avifPath: ?string, thumbPaths: array<string, string>, originalPath: ?string}|null */
    private function normalizeCover(?Photo $photo): ?array
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

    private function assertSlugAllowed(string $slug): void
    {
        if (ReservedAlbumSlugs::isReserved($slug)) {
            throw new BadRequestHttpException(\sprintf('slug "%s" is reserved.', $slug));
        }
    }
}
