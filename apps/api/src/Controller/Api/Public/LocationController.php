<?php

namespace App\Controller\Api\Public;

use App\Entity\Location;
use App\Entity\Photo;
use App\Entity\Tag;
use App\Http\Pagination;
use App\Repository\LocationRepository;
use App\Repository\PhotoRepository;
use App\Service\PhotoPublicNormalizer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[AsController]
#[Route('/api/locations')]
class LocationController
{
    private const DEFAULT_PHOTO_PER_PAGE = 48;

    public function __construct(
        private readonly LocationRepository $locations,
        private readonly PhotoRepository $photos,
        private readonly PhotoPublicNormalizer $photoNormalizer,
    ) {
    }

    #[Route('/{id}/photos', name: 'public_locations_photos', methods: ['GET'])]
    public function photos(string $id, Request $request): JsonResponse
    {
        $location = $this->findVisibleLocationOrFail($id);
        $page = Pagination::page($request);
        $perPage = Pagination::perPage($request, self::DEFAULT_PHOTO_PER_PAGE);
        $result = $this->photos->findVisibleByLocationIdPaginated($location->getId(), $page, $perPage);

        return new JsonResponse([
            'data' => array_map($this->normalizePhoto(...), $result['items']),
            'meta' => Pagination::meta($page, $perPage, $result['total']),
        ]);
    }

    #[Route('/{id}', name: 'public_locations_show', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        $location = $this->findVisibleLocationOrFail($id);

        return new JsonResponse(['data' => [
            'location' => $this->normalizeLocation($location),
        ]]);
    }

    private function findVisibleLocationOrFail(string $id): Location
    {
        try {
            $uuid = Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Location not found.');
        }

        $location = $this->locations->find($uuid);
        if (null === $location) {
            throw new NotFoundHttpException('Location not found.');
        }

        // Location pages are only public when they have at least one publicly
        // reachable photo (design spec §11) — no existence leak otherwise.
        if (0 === $this->photos->countVisibleByLocationId($uuid)) {
            throw new NotFoundHttpException('Location not found.');
        }

        return $location;
    }

    /** @return array<string, mixed> */
    private function normalizeLocation(Location $location): array
    {
        return [
            'id' => (string) $location->getId(),
            'name' => $location->getName(),
            'city' => $location->getCity(),
            'country' => $location->getCountry(),
            'latitude' => $location->getLatitude(),
            'longitude' => $location->getLongitude(),
        ];
    }

    /**
     * Media-relative paths only. Never absolute filesystem paths.
     *
     * @return array<string, mixed>
     */
    private function normalizePhoto(Photo $photo): array
    {
        return $this->photoNormalizer->summary($photo) + [
            'tags' => array_map($this->normalizeTag(...), $photo->getTags()->toArray()),
        ];
    }

    /** @return array<string, mixed> */
    private function normalizeTag(Tag $tag): array
    {
        return [
            'id' => (string) $tag->getId(),
            'name' => $tag->getName(),
            'slug' => $tag->getSlug(),
        ];
    }
}
