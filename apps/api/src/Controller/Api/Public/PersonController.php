<?php

namespace App\Controller\Api\Public;

use App\Entity\Location;
use App\Entity\Photo;
use App\Entity\Tag;
use App\Repository\PersonRepository;
use App\Repository\PhotoRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[AsController]
#[Route('/api/people')]
class PersonController
{
    public function __construct(
        private readonly PersonRepository $people,
        private readonly PhotoRepository $photos,
    ) {
    }

    #[Route('/{id}', name: 'public_people_show', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        $person = $this->findPersonOrFail($id);

        // Person pages are only public when they have at least one publicly
        // reachable photo (design spec §11) — no existence leak otherwise.
        if ([] === $this->photos->findVisibleByPersonId($person->getId())) {
            throw new NotFoundHttpException('Person not found.');
        }

        return new JsonResponse(['data' => [
            'id' => (string) $person->getId(),
            'name' => $person->getName(),
        ]]);
    }

    #[Route('/{id}/photos', name: 'public_people_photos', methods: ['GET'])]
    public function photos(string $id): JsonResponse
    {
        $person = $this->findPersonOrFail($id);
        $photos = $this->photos->findVisibleByPersonId($person->getId());

        return new JsonResponse(['data' => array_map($this->normalize(...), $photos)]);
    }

    private function findPersonOrFail(string $id): \App\Entity\Person
    {
        try {
            $uuid = Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Person not found.');
        }

        $person = $this->people->find($uuid);
        if (null === $person) {
            throw new NotFoundHttpException('Person not found.');
        }

        return $person;
    }

    /**
     * Never include `originalPath` (or any raw filesystem path derived from
     * it) in the public payload — only converted AVIF/thumb paths are safe
     * to expose here.
     *
     * @return array<string, mixed>
     */
    private function normalize(Photo $photo): array
    {
        return [
            'id' => (string) $photo->getId(),
            'albumId' => (string) $photo->getAlbum()->getId(),
            'title' => $photo->getTitle(),
            'takenAt' => $photo->getTakenAt()?->format(\DATE_ATOM),
            'width' => $photo->getWidth(),
            'height' => $photo->getHeight(),
            'avifPath' => $photo->getAvifPath(),
            'thumbPaths' => $photo->getThumbPaths(),
            'location' => $this->normalizeLocation($photo->getLocation()),
            'tags' => array_map($this->normalizeTag(...), $photo->getTags()->toArray()),
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
