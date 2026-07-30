<?php

namespace App\Controller\Api\Public;

use App\Entity\Album;
use App\Entity\Photo;
use App\Entity\Tag;
use App\Enum\AlbumVisibility;
use App\Repository\PhotoRepository;
use App\Service\ViewDeduplicatorInterface;
use App\Service\ViewVisitorIdentifier;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[AsController]
#[Route('/api/photos')]
class PhotoController
{
    public function __construct(
        private readonly PhotoRepository $photos,
        private readonly ViewDeduplicatorInterface $viewDeduplicator,
        private readonly ViewVisitorIdentifier $viewVisitor,
    ) {
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

    #[Route('/{id}/view', name: 'public_photos_record_view', methods: ['POST'])]
    public function recordView(string $id, Request $request): JsonResponse
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

        $visitorId = $this->viewVisitor->resolve($request);
        if ($this->viewDeduplicator->claim('photo', (string) $photo->getId(), $visitorId)) {
            $photo->setViewCount($this->photos->incrementViewCount($photo->getId()));
        }

        $response = new JsonResponse(['data' => ['viewCount' => $photo->getViewCount()]]);
        $this->viewVisitor->attachCookie($request, $response, $visitorId);

        return $response;
    }

    /**
     * Media-relative paths only (AVIF, thumbs, and staging original when
     * convert has not finished). Never absolute filesystem paths.
     *
     * @return array<string, mixed>
     */
    private function normalize(Photo $photo): array
    {
        [$prevId, $nextId] = $this->adjacentIds($photo);
        $album = $photo->getAlbum();

        return [
            'id' => (string) $photo->getId(),
            'albumId' => (string) $album->getId(),
            'albumSlug' => $album->getSlug(),
            'albumTitle' => $album->getTitle(),
            'albumAncestors' => $this->albumAncestors($album),
            'title' => $photo->getTitle(),
            'width' => $photo->getWidth(),
            'height' => $photo->getHeight(),
            'avifPath' => $photo->getAvifPath(),
            'thumbPaths' => $photo->getThumbPaths(),
            'originalPath' => $photo->getOriginalPath(),
            'viewCount' => $photo->getViewCount(),
            'tags' => array_map($this->normalizeTag(...), $photo->getTags()->toArray()),
            'people' => $this->normalizePeople($photo),
            'prevId' => $prevId,
            'nextId' => $nextId,
        ];
    }

    /**
     * Same visibility-stopping walk as public album detail — never leak a
     * private ancestor's slug/title into the photo breadcrumb.
     *
     * @return array<int, array{slug: string, title: string}>
     */
    private function albumAncestors(Album $album): array
    {
        $chain = [];
        $current = $album->getParent();
        while ($this->isVisible($current)) {
            $chain[] = ['slug' => $current->getSlug(), 'title' => $current->getTitle()];
            $current = $current->getParent();
        }

        return array_reverse($chain);
    }

    private function isVisible(?Album $album): bool
    {
        return null !== $album && AlbumVisibility::Private !== $album->getVisibility();
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

    /** @return array<int, array{id: string, name: ?string, avatarCropPath: ?string}> */
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
            $people[] = [
                'id' => $personId,
                'name' => $person->getName(),
                'avatarCropPath' => $person->getEffectiveAvatarPath(),
            ];
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
