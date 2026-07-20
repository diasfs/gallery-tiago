<?php

namespace App\Controller\Api\Public;

use App\Entity\Photo;
use App\Entity\Tag;
use App\Repository\PhotoRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[AsController]
#[Route('/api/photos')]
class PhotoController
{
    public function __construct(private readonly PhotoRepository $photos)
    {
    }

    #[Route('/{id}', name: 'public_photos_show', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        try {
            $uuid = Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Photo not found.');
        }

        $photo = $this->photos->findVisibleById($uuid);
        if (null === $photo) {
            throw new NotFoundHttpException('Photo not found.');
        }

        return new JsonResponse(['data' => $this->normalize($photo)]);
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
        [$prevId, $nextId] = $this->adjacentIds($photo);

        return [
            'id' => (string) $photo->getId(),
            'albumId' => (string) $photo->getAlbum()->getId(),
            'albumSlug' => $photo->getAlbum()->getSlug(),
            'title' => $photo->getTitle(),
            'width' => $photo->getWidth(),
            'height' => $photo->getHeight(),
            'avifPath' => $photo->getAvifPath(),
            'thumbPaths' => $photo->getThumbPaths(),
            'tags' => array_map($this->normalizeTag(...), $photo->getTags()->toArray()),
            'people' => $this->normalizePeople($photo),
            'prevId' => $prevId,
            'nextId' => $nextId,
        ];
    }

    /** @return array{0: ?string, 1: ?string} */
    private function adjacentIds(Photo $photo): array
    {
        $siblings = $this->photos->findByAlbum($photo->getAlbum());
        $index = array_search($photo->getId()->toRfc4122(), array_map(
            static fn (Photo $p): string => $p->getId()->toRfc4122(),
            $siblings,
        ), true);

        if (false === $index) {
            return [null, null];
        }

        $prev = $index > 0 ? $siblings[$index - 1] : null;
        $next = $index < \count($siblings) - 1 ? $siblings[$index + 1] : null;

        return [$prev?->getId()->toRfc4122(), $next?->getId()->toRfc4122()];
    }

    /** @return array<int, array{id: string, name: ?string}> */
    private function normalizePeople(Photo $photo): array
    {
        $seen = [];
        $people = [];
        foreach ($photo->getFaces() as $face) {
            $person = $face->getPerson();
            if (null === $person) {
                continue;
            }
            $personId = (string) $person->getId();
            if (isset($seen[$personId])) {
                continue;
            }
            $seen[$personId] = true;
            $people[] = ['id' => $personId, 'name' => $person->getName()];
        }

        return $people;
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
