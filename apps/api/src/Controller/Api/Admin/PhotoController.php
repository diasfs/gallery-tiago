<?php

namespace App\Controller\Api\Admin;

use App\Entity\Location;
use App\Entity\Photo;
use App\Entity\Tag;
use App\Enum\ProcessingStatus;
use App\Message\ConvertMediaMessage;
use App\Message\DetectFacesMessage;
use App\Repository\LocationRepository;
use App\Repository\PhotoRepository;
use App\Repository\TagRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[AsController]
#[Route('/api/admin/photos')]
class PhotoController
{
    public function __construct(
        private readonly PhotoRepository $photos,
        private readonly LocationRepository $locations,
        private readonly TagRepository $tags,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
    ) {
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

        if (\array_key_exists('takenAt', $payload)) {
            $photo->setTakenAt($this->parseTakenAt($payload['takenAt']));
        }

        if (\array_key_exists('locationId', $payload)) {
            $photo->setLocation($this->resolveLocation($payload['locationId']));
        }

        if (\array_key_exists('tagIds', $payload)) {
            $this->applyTags($photo, $payload['tagIds']);
        }

        $this->em->flush();

        return new JsonResponse(['data' => $this->normalize($photo)]);
    }

    /**
     * Deletes prior auto-detected faces (`hasEmbedding = true`) only —
     * manually-added faces are left untouched (spec §9) — then re-enqueues
     * the pipeline: `detect_faces` directly if an AVIF master already
     * exists, otherwise `convert_media` first (which enqueues detection
     * itself on success).
     */
    #[Route('/{id}/reprocess', name: 'admin_photos_reprocess', methods: ['POST'])]
    public function reprocess(string $id): JsonResponse
    {
        $photo = $this->findOrFail($id);

        foreach ($photo->getFaces() as $face) {
            if ($face->hasEmbedding()) {
                $this->em->remove($face);
            }
        }

        $photoId = (string) $photo->getId();

        if (null === $photo->getAvifPath()) {
            $photo->setProcessingStatus(ProcessingStatus::Pending);
            $this->em->flush();
            $this->bus->dispatch(new ConvertMediaMessage($photoId));
        } else {
            $photo->setProcessingStatus(ProcessingStatus::Detecting);
            $this->em->flush();
            $this->bus->dispatch(new DetectFacesMessage($photoId));
        }

        return new JsonResponse(['data' => $this->normalize($photo)]);
    }

    private function parseTakenAt(mixed $value): ?\DateTimeImmutable
    {
        if (null === $value) {
            return null;
        }
        if (!\is_string($value)) {
            throw new BadRequestHttpException('takenAt must be an ISO-8601 date string or null.');
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            throw new BadRequestHttpException('takenAt must be a valid date string.');
        }
    }

    private function resolveLocation(mixed $value): ?Location
    {
        if (null === $value) {
            return null;
        }
        if (!\is_string($value)) {
            throw new BadRequestHttpException('locationId must be a string or null.');
        }

        try {
            $uuid = Uuid::fromString($value);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Location not found.');
        }

        $location = $this->locations->find($uuid);
        if (null === $location) {
            throw new NotFoundHttpException('Location not found.');
        }

        return $location;
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
            'takenAt' => $photo->getTakenAt()?->format(\DATE_ATOM),
            'width' => $photo->getWidth(),
            'height' => $photo->getHeight(),
            'avifPath' => $photo->getAvifPath(),
            'thumbPaths' => $photo->getThumbPaths(),
            'processingStatus' => $photo->getProcessingStatus()->value,
            'location' => $this->normalizeLocation($photo->getLocation()),
            'tags' => array_map($this->normalizeTag(...), $photo->getTags()->toArray()),
            'createdAt' => $photo->getCreatedAt()->format(\DATE_ATOM),
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
