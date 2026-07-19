<?php

namespace App\Controller\Api\Admin;

use App\Entity\Album;
use App\Entity\Photo;
use App\Enum\AlbumVisibility;
use App\Repository\AlbumRepository;
use App\Repository\PhotoRepository;
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
#[Route('/api/admin/albums')]
class AlbumController
{
    public function __construct(
        private readonly AlbumRepository $albums,
        private readonly PhotoRepository $photos,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'admin_albums_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $albums = array_map($this->normalize(...), $this->albums->findAllOrdered());

        return new JsonResponse(['data' => $albums]);
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

        $album = new Album($title, $slug);
        $this->applyPayload($album, $payload);

        $this->em->persist($album);
        $this->em->flush();

        return new JsonResponse(['data' => $this->normalize($album)], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'admin_albums_show', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        return new JsonResponse(['data' => $this->normalize($this->findOrFail($id))]);
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

        if (!$album->getChildren()->isEmpty() || !$album->getPhotos()->isEmpty()) {
            throw new ConflictHttpException('Album has child albums or photos and cannot be deleted.');
        }

        $this->em->remove($album);
        $this->em->flush();

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

        if (\array_key_exists('parentId', $payload)) {
            if (null === $payload['parentId']) {
                $album->setParent(null);
            } else {
                $parent = $this->findOrFail((string) $payload['parentId']);
                $album->setParent($parent);
            }
        }

        if (\array_key_exists('coverPhotoId', $payload)) {
            $album->setCoverPhoto($this->resolveCoverPhoto($payload['coverPhotoId']));
        }
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

    /** @return array<string, mixed> */
    private function decode(Request $request): array
    {
        try {
            return $request->toArray();
        } catch (\JsonException) {
            throw new BadRequestHttpException('Invalid JSON body.');
        }
    }

    private function normalize(Album $album): array
    {
        return [
            'id' => (string) $album->getId(),
            'title' => $album->getTitle(),
            'slug' => $album->getSlug(),
            'description' => $album->getDescription(),
            'visibility' => $album->getVisibility()->value,
            'sortOrder' => $album->getSortOrder(),
            'coverPhotoId' => $album->getCoverPhoto()?->getId()->toRfc4122(),
            'parentId' => $album->getParent()?->getId()->toRfc4122(),
            'childCount' => $album->getChildren()->count(),
            'photoCount' => $album->getPhotos()->count(),
            'createdAt' => $album->getCreatedAt()->format(\DATE_ATOM),
            'updatedAt' => $album->getUpdatedAt()->format(\DATE_ATOM),
        ];
    }
}
