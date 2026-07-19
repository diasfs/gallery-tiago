<?php

namespace App\Controller\Api\Public;

use App\Entity\Location;
use App\Entity\Photo;
use App\Entity\Tag;
use App\Repository\LocationRepository;
use App\Repository\PhotoRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[AsController]
#[Route('/api/locations')]
class LocationController
{
    public function __construct(
        private readonly LocationRepository $locations,
        private readonly PhotoRepository $photos,
    ) {
    }

    #[Route('/{id}', name: 'public_locations_show', methods: ['GET'])]
    public function show(string $id): JsonResponse
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

        return new JsonResponse(['data' => [
            'location' => $this->normalizeLocation($location),
            'photos' => array_map($this->normalizePhoto(...), $this->photos->findVisibleByLocationId($uuid)),
        ]]);
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
     * Never include `originalPath` in the public payload.
     *
     * @return array<string, mixed>
     */
    private function normalizePhoto(Photo $photo): array
    {
        return [
            'id' => (string) $photo->getId(),
            'albumId' => (string) $photo->getAlbum()->getId(),
            'title' => $photo->getTitle(),
            'takenAt' => $photo->getTakenAt()?->format(\DATE_ATOM),
            'avifPath' => $photo->getAvifPath(),
            'thumbPaths' => $photo->getThumbPaths(),
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
