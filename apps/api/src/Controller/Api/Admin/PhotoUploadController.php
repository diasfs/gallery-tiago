<?php

namespace App\Controller\Api\Admin;

use App\Entity\Album;
use App\Entity\Photo;
use App\Http\Pagination;
use App\Message\ConvertMediaMessage;
use App\Repository\AlbumRepository;
use App\Repository\PhotoRepository;
use App\Exception\ProcessingStageDisabledException;
use App\Service\MediaStorage;
use App\Service\PhotoReprocessor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[AsController]
#[Route('/api/admin/albums/{albumId}/photos')]
class PhotoUploadController
{
    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
    private const DEFAULT_PHOTO_PER_PAGE = 48;

    public function __construct(
        private readonly AlbumRepository $albums,
        private readonly PhotoRepository $photos,
        private readonly EntityManagerInterface $em,
        private readonly MediaStorage $storage,
        private readonly MessageBusInterface $bus,
        private readonly PhotoReprocessor $reprocessor,
    ) {
    }

    #[Route('', name: 'admin_photos_list', methods: ['GET'])]
    public function list(string $albumId, Request $request): JsonResponse
    {
        $album = $this->findAlbumOrFail($albumId);
        $page = Pagination::page($request);
        $perPage = Pagination::perPage($request, self::DEFAULT_PHOTO_PER_PAGE);
        $result = $this->photos->findByAlbumPaginated($album, $page, $perPage);

        return new JsonResponse([
            'data' => array_map($this->normalize(...), $result['items']),
            'meta' => Pagination::meta($page, $perPage, $result['total']),
        ]);
    }

    /**
     * Re-runs the async pipeline for every photo in the album. Accepts an
     * optional JSON body {"scope": "all"|"faces"|"tags"} (see PhotoReprocessor
     * for scope semantics).
     */
    #[Route('/reprocess', name: 'admin_album_photos_reprocess', methods: ['POST'])]
    public function reprocess(string $albumId, Request $request): JsonResponse
    {
        $album = $this->findAlbumOrFail($albumId);
        $scope = $this->resolveScope($request);

        $photos = $this->photos->findByAlbum($album);
        try {
            foreach ($photos as $photo) {
                $this->reprocessor->reprocess($photo, $scope);
            }
        } catch (ProcessingStageDisabledException $e) {
            throw new ConflictHttpException($e->getMessage(), $e);
        }

        return new JsonResponse(['data' => array_map($this->normalize(...), $photos)]);
    }

    #[Route('', name: 'admin_photos_upload', methods: ['POST'])]
    public function upload(string $albumId, Request $request): JsonResponse
    {
        $album = $this->findAlbumOrFail($albumId);

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            throw new BadRequestHttpException('A "file" upload is required.');
        }
        if (!$file->isValid()) {
            throw new BadRequestHttpException('Upload failed: '.$file->getErrorMessage());
        }
        if (!\in_array($file->getMimeType(), self::ALLOWED_MIME_TYPES, true)) {
            throw new BadRequestHttpException('Unsupported file type; expected JPEG, PNG, or WebP.');
        }

        $photo = new Photo($album);
        $title = pathinfo($file->getClientOriginalName(), \PATHINFO_FILENAME);
        if (\is_string($title) && '' !== $title) {
            $photo->setTitle($title);
        }
        $photo->setSortOrder($this->photos->nextSortOrderForAlbum($album));
        $this->em->persist($photo);
        $this->em->flush();

        $photoId = (string) $photo->getId();
        $relativePath = $this->storage->storeOriginal($file, $photoId);

        $photo->setOriginalPath($relativePath);
        $this->em->flush();

        $this->bus->dispatch(new ConvertMediaMessage($photoId));

        return new JsonResponse(['data' => $this->normalize($photo)], Response::HTTP_CREATED);
    }

    /**
     * Replaces the within-album order. `photoIds` must list every photo in the album.
     */
    #[Route('/order', name: 'admin_album_photos_order', methods: ['PUT'])]
    public function reorder(string $albumId, Request $request): JsonResponse
    {
        $album = $this->findAlbumOrFail($albumId);

        try {
            $payload = $request->toArray();
        } catch (\JsonException) {
            throw new BadRequestHttpException('Invalid JSON body.');
        }

        $photoIds = $payload['photoIds'] ?? null;
        if (!\is_array($photoIds) || [] === $photoIds) {
            throw new BadRequestHttpException('photoIds must be a non-empty array of photo ids.');
        }

        $ordered = [];
        foreach ($photoIds as $id) {
            if (!\is_string($id) || '' === $id) {
                throw new BadRequestHttpException('photoIds must contain only non-empty strings.');
            }
            $ordered[] = $id;
        }

        if (\count($ordered) !== \count(array_unique($ordered))) {
            throw new BadRequestHttpException('photoIds must not contain duplicates.');
        }

        $photos = $this->photos->findByAlbum($album);
        if (\count($ordered) !== \count($photos)) {
            throw new BadRequestHttpException('photoIds must include every photo in the album exactly once.');
        }

        $byId = [];
        foreach ($photos as $photo) {
            $byId[(string) $photo->getId()] = $photo;
        }

        foreach ($ordered as $id) {
            if (!isset($byId[$id])) {
                throw new BadRequestHttpException('photoIds contains a photo that does not belong to this album.');
            }
        }

        foreach ($ordered as $index => $id) {
            $byId[$id]->setSortOrder($index);
        }
        $this->em->flush();

        return new JsonResponse([
            'data' => array_map($this->normalize(...), $this->photos->findByAlbum($album)),
        ]);
    }

    private function resolveScope(Request $request): string
    {
        if ('' === $request->getContent()) {
            return PhotoReprocessor::SCOPE_ALL;
        }

        try {
            $payload = $request->toArray();
        } catch (\JsonException) {
            throw new BadRequestHttpException('Invalid JSON body.');
        }

        $scope = $payload['scope'] ?? PhotoReprocessor::SCOPE_ALL;
        if (!\is_string($scope) || !\in_array($scope, PhotoReprocessor::SCOPES, true)) {
            throw new BadRequestHttpException('scope must be one of: all, faces, tags.');
        }

        return $scope;
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

    private function normalize(Photo $photo): array
    {
        return [
            'id' => (string) $photo->getId(),
            'albumId' => (string) $photo->getAlbum()->getId(),
            'title' => $photo->getTitle(),
            'avifPath' => $photo->getAvifPath(),
            'thumbPaths' => $photo->getThumbPaths(),
            'originalPath' => $photo->getOriginalPath(),
            'mediaStatus' => $photo->getMediaStatus()->value,
            'facesStatus' => $photo->getFacesStatus()->value,
            'tagsStatus' => $photo->getTagsStatus()->value,
            'processingError' => $photo->getProcessingError(),
            'sortOrder' => $photo->getSortOrder(),
            'createdAt' => $photo->getCreatedAt()->format(\DATE_ATOM),
        ];
    }
}
