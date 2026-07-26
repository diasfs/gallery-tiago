<?php

namespace App\Controller\Api\Admin;

use App\Entity\Photo;
use App\Entity\Tag;
use App\Repository\PhotoRepository;
use App\Repository\TagRepository;
use App\Service\PhotoDeleter;
use App\Service\PhotoReprocessor;
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
#[Route('/api/admin/photos')]
class PhotoController
{
    public function __construct(
        private readonly PhotoRepository $photos,
        private readonly TagRepository $tags,
        private readonly EntityManagerInterface $em,
        private readonly PhotoDeleter $photoDeleter,
        private readonly PhotoReprocessor $reprocessor,
    ) {
    }

    #[Route('/bulk-delete', name: 'admin_photos_bulk_delete', methods: ['POST'])]
    public function bulkDelete(Request $request): Response
    {
        $payload = $this->decode($request);
        $ids = $payload['ids'] ?? null;
        if (!\is_array($ids) || [] === $ids) {
            throw new BadRequestHttpException('ids must be a non-empty array of photo ids.');
        }

        $photos = [];
        foreach ($ids as $id) {
            if (!\is_string($id)) {
                throw new BadRequestHttpException('ids must contain string ids.');
            }
            $photos[] = $this->findOrFail($id);
        }

        $this->photoDeleter->deleteMany($photos);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}', name: 'admin_photos_show', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        return new JsonResponse(['data' => $this->normalize($this->findOrFail($id))]);
    }

    #[Route('/{id}', name: 'admin_photos_delete', methods: ['DELETE'])]
    public function delete(string $id): Response
    {
        $this->photoDeleter->delete($this->findOrFail($id));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}', name: 'admin_photos_update', methods: ['PATCH'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $photo = $this->findOrFail($id);
        $payload = $this->decode($request);

        if (\array_key_exists('title', $payload)) {
            if (null !== $payload['title'] && !\is_string($payload['title'])) {
                throw new BadRequestHttpException('title must be a string or null.');
            }
            $photo->setTitle($payload['title']);
        }

        if (\array_key_exists('tagIds', $payload)) {
            $this->applyTags($photo, $payload['tagIds']);
        }

        $this->em->flush();

        return new JsonResponse(['data' => $this->normalize($photo)]);
    }

    /**
     * Re-runs the async pipeline (see PhotoReprocessor for scope semantics).
     * Accepts an optional JSON body {"scope": "all"|"faces"|"tags"}.
     */
    #[Route('/{id}/reprocess', name: 'admin_photos_reprocess', methods: ['POST'])]
    public function reprocess(string $id, Request $request): JsonResponse
    {
        $photo = $this->findOrFail($id);
        $scope = $this->resolveScope($request);

        $this->reprocessor->reprocess($photo, $scope);

        return new JsonResponse(['data' => $this->normalize($photo)]);
    }

    private function resolveScope(Request $request): string
    {
        if ('' === $request->getContent()) {
            return PhotoReprocessor::SCOPE_ALL;
        }

        $payload = $this->decode($request);
        $scope = $payload['scope'] ?? PhotoReprocessor::SCOPE_ALL;
        if (!\is_string($scope) || !\in_array($scope, PhotoReprocessor::SCOPES, true)) {
            throw new BadRequestHttpException('scope must be one of: all, faces, tags.');
        }

        return $scope;
    }

    private function applyTags(Photo $photo, mixed $tagIds): void
    {
        if (!\is_array($tagIds)) {
            throw new BadRequestHttpException('tagIds must be an array.');
        }

        $tags = array_map($this->resolveTag(...), $tagIds);

        foreach ($photo->getTags()->toArray() as $existing) {
            if (!\in_array($existing, $tags, true)) {
                $photo->removeTag($existing);
            }
        }
        foreach ($tags as $tag) {
            $photo->addTag($tag);
        }
    }

    private function resolveTag(mixed $tagId): Tag
    {
        if (!\is_string($tagId)) {
            throw new BadRequestHttpException('tagIds must contain string ids.');
        }

        try {
            $uuid = Uuid::fromString($tagId);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Tag not found.');
        }

        $tag = $this->tags->find($uuid);
        if (null === $tag) {
            throw new NotFoundHttpException('Tag not found.');
        }

        return $tag;
    }

    private function findOrFail(string $id): Photo
    {
        try {
            $uuid = Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Photo not found.');
        }

        $photo = $this->photos->find($uuid);
        if (null === $photo) {
            throw new NotFoundHttpException('Photo not found.');
        }

        return $photo;
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

    /** @return array<string, mixed> */
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
            'mediaStatus' => $photo->getMediaStatus()->value,
            'facesStatus' => $photo->getFacesStatus()->value,
            'tagsStatus' => $photo->getTagsStatus()->value,
            'processingError' => $photo->getProcessingError(),
            'tags' => array_map($this->normalizeTag(...), $photo->getTags()->toArray()),
            'people' => $this->normalizePeople($photo),
            'createdAt' => $photo->getCreatedAt()->format(\DATE_ATOM),
        ];
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
