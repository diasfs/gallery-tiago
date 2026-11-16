<?php

namespace App\Controller\Api\Admin;

use App\Entity\Album;
use App\Entity\Face;
use App\Entity\Photo;
use App\Entity\Tag;
use App\Repository\AlbumRepository;
use App\Repository\FaceRepository;
use App\Repository\PhotoRepository;
use App\Repository\TagRepository;
use App\Exception\ProcessingStageDisabledException;
use App\Service\FaceDeleter;
use App\Service\PhotoDeleter;
use App\Service\PhotoMover;
use App\Service\PhotoReprocessor;
use App\Service\PhotoRotator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[AsController]
#[Route('/api/admin/photos')]
class PhotoController
{
    public function __construct(
        private readonly PhotoRepository $photos,
        private readonly AlbumRepository $albums,
        private readonly TagRepository $tags,
        private readonly FaceRepository $faces,
        private readonly EntityManagerInterface $em,
        private readonly PhotoDeleter $photoDeleter,
        private readonly PhotoMover $photoMover,
        private readonly FaceDeleter $faceDeleter,
        private readonly PhotoReprocessor $reprocessor,
        private readonly PhotoRotator $rotator,
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

    #[Route('/bulk-move', name: 'admin_photos_bulk_move', methods: ['POST'])]
    public function bulkMove(Request $request): Response
    {
        $payload = $this->decode($request);
        $ids = $payload['ids'] ?? null;
        if (!\is_array($ids) || [] === $ids) {
            throw new BadRequestHttpException('ids must be a non-empty array of photo ids.');
        }

        $albumId = $payload['albumId'] ?? null;
        if (!\is_string($albumId) || '' === $albumId) {
            throw new BadRequestHttpException('albumId must be a string.');
        }

        $photos = [];
        foreach ($ids as $id) {
            if (!\is_string($id)) {
                throw new BadRequestHttpException('ids must contain string ids.');
            }
            $photos[] = $this->findOrFail($id);
        }

        $album = $this->findAlbumOrFail($albumId);

        try {
            $this->photoMover->move($photos, $album);
        } catch (\InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage(), $e);
        } catch (\RuntimeException $e) {
            throw new ConflictHttpException($e->getMessage(), $e);
        }

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

        try {
            $this->reprocessor->reprocess($photo, $scope);
        } catch (ProcessingStageDisabledException $e) {
            throw new ConflictHttpException($e->getMessage(), $e);
        }

        return new JsonResponse(['data' => $this->normalize($photo)]);
    }

    #[Route('/{id}/rotate', name: 'admin_photos_rotate', methods: ['POST'])]
    public function rotate(string $id, Request $request): JsonResponse
    {
        $photo = $this->findOrFail($id);
        $degrees = 90;
        if ('' !== $request->getContent()) {
            $payload = $this->decode($request);
            $raw = $payload['degrees'] ?? 90;
            if (!\is_int($raw) && !(\is_string($raw) && is_numeric($raw))) {
                throw new BadRequestHttpException('degrees must be an integer.');
            }
            $degrees = (int) $raw;
        }

        try {
            $this->rotator->rotate($photo, $degrees);
        } catch (\InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage(), $e);
        } catch (\RuntimeException $e) {
            throw new ConflictHttpException($e->getMessage(), $e);
        }

        return new JsonResponse(['data' => $this->normalize($photo)]);
    }

    #[Route('/{photoId}/faces/{faceId}/restore', name: 'admin_photos_faces_restore', methods: ['POST'])]
    public function restoreFace(string $photoId, string $faceId): JsonResponse
    {
        $photo = $this->findOrFail($photoId);
        $face = $this->findFaceOrFail($faceId);
        if ($face->getPhoto()?->getId()?->equals($photo->getId()) !== true) {
            throw new NotFoundHttpException('Face not found.');
        }
        if (!$face->isDeleted()) {
            throw new NotFoundHttpException('Face not found in trash.');
        }

        $this->faceDeleter->restore($face);

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

    private function findAlbumOrFail(string $id): Album
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

    private function findFaceOrFail(string $id): Face
    {
        try {
            $uuid = Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Face not found.');
        }

        $face = $this->faces->find($uuid);
        if (null === $face) {
            throw new NotFoundHttpException('Face not found.');
        }

        return $face;
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
            'originalPath' => $photo->getOriginalPath(),
            'mediaStatus' => $photo->getMediaStatus()->value,
            'facesStatus' => $photo->getFacesStatus()->value,
            'tagsStatus' => $photo->getTagsStatus()->value,
            'processingError' => $photo->getProcessingError(),
            'tags' => array_map($this->normalizeTag(...), $photo->getTags()->toArray()),
            'people' => $this->normalizePeople($photo),
            'faces' => $this->normalizeFaces($photo),
            'excludedFaces' => $this->normalizeExcludedFaces($photo),
            'createdAt' => $photo->getCreatedAt()->format(\DATE_ATOM),
        ];
    }

    /** @return array<int, array{id: string, name: ?string, avatarCropPath: ?string}> */
    private function normalizePeople(Photo $photo): array
    {
        $seen = [];
        $people = [];
        foreach ($photo->getFaces() as $face) {
            if ($face->isDeleted()) {
                continue;
            }
            $person = $face->getPerson();
            if (null === $person || $person->isDeleted()) {
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

    /**
     * Active faces with person label for admin photo overlays and the people editor.
     * Faces without a bbox (manual add) are listed with a zero-size box so overlays skip them.
     *
     * @return list<array{id: string, personId: ?string, name: ?string, x: float, y: float, width: float, height: float, cropPath: ?string}>
     */
    private function normalizeFaces(Photo $photo): array
    {
        $faces = [];
        foreach ($photo->getFaces() as $face) {
            if ($face->isDeleted()) {
                continue;
            }
            $person = $face->getPerson();
            if (null !== $person && $person->isDeleted()) {
                continue;
            }
            $faces[] = $this->faceOverlayRow($face);
        }

        return $faces;
    }

    /**
     * Soft-deleted faces or faces whose person is in the trash.
     *
     * @return list<array{
     *     id: string,
     *     personId: ?string,
     *     name: ?string,
     *     x: float,
     *     y: float,
     *     width: float,
     *     height: float,
     *     avatarCropPath: ?string,
     *     exclusionReason: 'removed'|'person_trashed',
     *     faceDeletedAt: ?string,
     *     personDeletedAt: ?string
     * }>
     */
    private function normalizeExcludedFaces(Photo $photo): array
    {
        $faces = [];
        foreach ($photo->getFaces() as $face) {
            $person = $face->getPerson();
            $faceDeleted = $face->isDeleted();
            $personTrashed = null !== $person && $person->isDeleted();
            if (!$faceDeleted && !$personTrashed) {
                continue;
            }
            // Always list excluded faces (even without bbox) so the restore
            // card is usable; overlays still require a positive-size box.
            $x = $face->getX() ?? 0.0;
            $y = $face->getY() ?? 0.0;
            $width = $face->getWidth() ?? 0.0;
            $height = $face->getHeight() ?? 0.0;
            $crop = $face->getCropPath();
            if (null === $crop || '' === $crop) {
                $crop = $person?->getEffectiveAvatarPath();
            }
            $faces[] = [
                'id' => (string) $face->getId(),
                'personId' => null !== $person ? (string) $person->getId() : null,
                'name' => $person?->getName(),
                'x' => $x,
                'y' => $y,
                'width' => $width,
                'height' => $height,
                'avatarCropPath' => $crop,
                'exclusionReason' => $faceDeleted ? 'removed' : 'person_trashed',
                'faceDeletedAt' => $face->getDeletedAt()?->format(\DATE_ATOM),
                'personDeletedAt' => $person?->getDeletedAt()?->format(\DATE_ATOM),
            ];
        }

        return $faces;
    }

    /**
     * @return array{id: string, personId: ?string, name: ?string, x: float, y: float, width: float, height: float, cropPath: ?string}
     */
    private function faceOverlayRow(Face $face): array
    {
        $person = $face->getPerson();

        return [
            'id' => (string) $face->getId(),
            'personId' => null !== $person ? (string) $person->getId() : null,
            'name' => $person?->getName(),
            'x' => $face->getX() ?? 0.0,
            'y' => $face->getY() ?? 0.0,
            'width' => $face->getWidth() ?? 0.0,
            'height' => $face->getHeight() ?? 0.0,
            'cropPath' => $face->getCropPath(),
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
