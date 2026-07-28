<?php

namespace App\Controller\Api\Public;

use App\Entity\Photo;
use App\Entity\Tag;
use App\Http\Pagination;
use App\Repository\PersonRepository;
use App\Repository\PhotoRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[AsController]
#[Route('/api/people')]
class PersonController
{
    private const DEFAULT_PHOTO_PER_PAGE = 48;

    public function __construct(
        private readonly PersonRepository $people,
        private readonly PhotoRepository $photos,
    ) {
    }

    #[Route('', name: 'public_people_search', methods: ['GET'], priority: 10)]
    public function search(Request $request): JsonResponse
    {
        $q = $request->query->get('q');
        $q = \is_string($q) ? $q : null;
        $people = $this->people->searchNamedPublic($q);

        return new JsonResponse([
            'data' => array_map(static fn ($person) => [
                'id' => (string) $person->getId(),
                'name' => $person->getName(),
            ], $people),
        ]);
    }

    #[Route('/{id}', name: 'public_people_show', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        $person = $this->findPersonOrFail($id);

        // Person pages are only public when they have at least one publicly
        // reachable photo (design spec §11) — no existence leak otherwise.
        if (0 === $this->photos->countVisibleByPersonId($person->getId())) {
            throw new NotFoundHttpException('Person not found.');
        }

        return new JsonResponse(['data' => [
            'id' => (string) $person->getId(),
            'name' => $person->getName(),
        ]]);
    }

    #[Route('/{id}/photos', name: 'public_people_photos', methods: ['GET'])]
    public function photos(string $id, Request $request): JsonResponse
    {
        $person = $this->findPersonOrFail($id);
        $page = Pagination::page($request);
        $perPage = Pagination::perPage($request, self::DEFAULT_PHOTO_PER_PAGE);
        $result = $this->photos->findVisibleByPersonIdPaginated($person->getId(), $page, $perPage);

        return new JsonResponse([
            'data' => array_map($this->normalize(...), $result['items']),
            'meta' => Pagination::meta($page, $perPage, $result['total']),
        ]);
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
     * Media-relative paths only. Never absolute filesystem paths.
     *
     * @return array<string, mixed>
     */
    private function normalize(Photo $photo): array
    {
        return [
            'id' => (string) $photo->getId(),
            'albumId' => (string) $photo->getAlbum()->getId(),
            'title' => $photo->getTitle(),
            'width' => $photo->getWidth(),
            'height' => $photo->getHeight(),
            'avifPath' => $photo->getAvifPath(),
            'thumbPaths' => $photo->getThumbPaths(),
            'originalPath' => $photo->getOriginalPath(),
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
