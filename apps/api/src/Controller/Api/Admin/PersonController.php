<?php

namespace App\Controller\Api\Admin;

use App\Entity\Face;
use App\Entity\Person;
use App\Http\Pagination;
use App\Repository\FaceRepository;
use App\Repository\PersonRepository;
use App\Repository\PhotoRepository;
use App\Service\FaceEmbeddingClientInterface;
use App\Service\FaceSimilarityService;
use App\Service\MediaStorage;
use App\Service\PersonDeleter;
use App\Service\PersonMerger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[AsController]
class PersonController
{
    private const ALLOWED_AVATAR_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        private readonly PersonRepository $people,
        private readonly PhotoRepository $photos,
        private readonly FaceRepository $faces,
        private readonly PersonMerger $merger,
        private readonly PersonDeleter $personDeleter,
        private readonly FaceSimilarityService $similarity,
        private readonly FaceEmbeddingClientInterface $embeddingClient,
        private readonly MediaStorage $storage,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/api/admin/people/merge-suggestions', name: 'admin_people_merge_suggestions', methods: ['GET'])]
    public function mergeSuggestions(): JsonResponse
    {
        $unnamedClusterCount = $this->faces->countUnnamedClustersWithEmbeddings();
        $started = hrtime(true);
        $result = $this->similarity->findUnnamedMergeSuggestions();

        return new JsonResponse([
            'data' => $result['items'],
            'meta' => [
                'unnamedClusterCount' => $unnamedClusterCount,
                'analyzedClusterCount' => $result['analyzedClusterCount'],
                'truncated' => $result['truncated'],
                'durationMs' => (int) ((hrtime(true) - $started) / 1_000_000),
            ],
        ]);
    }

    #[Route('/api/admin/people/search-by-face', name: 'admin_people_search_by_face', methods: ['POST'])]
    public function searchByFace(Request $request): JsonResponse
    {
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            throw new BadRequestHttpException('A "file" upload is required.');
        }
        if (!$file->isValid()) {
            throw new BadRequestHttpException('Upload failed: '.$file->getErrorMessage());
        }
        if (!\in_array($file->getMimeType(), self::ALLOWED_AVATAR_MIME_TYPES, true)) {
            throw new BadRequestHttpException('Unsupported file type; expected JPEG, PNG, or WebP.');
        }

        try {
            $embedding = $this->embeddingClient->embedUpload($file);
        } catch (\RuntimeException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }

        return new JsonResponse([
            'data' => $this->similarity->searchPeopleByEmbedding($embedding),
        ]);
    }

    #[Route('/api/admin/people/unnamed', name: 'admin_people_unnamed', methods: ['GET'])]
    public function unnamed(): JsonResponse
    {
        $people = $this->people->findUnnamed();

        return new JsonResponse(['data' => array_map($this->normalizeCluster(...), $people)]);
    }

    /**
     * List/search people. `scope` defaults to `named` so the photo-edit picker
     * and merge dropdown keep their previous behaviour.
     */
    #[Route('/api/admin/people', name: 'admin_people_search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $scope = $request->query->getString('scope', 'named');
        if (!\in_array($scope, ['all', 'named', 'unnamed'], true)) {
            throw new BadRequestHttpException('scope must be all, named, or unnamed.');
        }

        $q = $request->query->get('q');
        $page = Pagination::page($request);
        $perPage = Pagination::perPage($request, 50, 100);
        $result = $this->people->searchPaginated(
            $scope,
            \is_string($q) ? $q : null,
            $page,
            $perPage,
        );
        $ids = array_map(
            static fn (Person $person): string => (string) $person->getId(),
            $result['items'],
        );
        $faceSummaries = $this->people->summarizeFacesForPersonIds($ids);

        $data = array_map(
            fn (Person $person): array => $this->normalizePerson(
                $person,
                $faceSummaries[(string) $person->getId()] ?? [
                    'faceCount' => 0,
                    'fallbackCropPath' => null,
                ],
            ),
            $result['items'],
        );

        return new JsonResponse([
            'data' => $data,
            'meta' => Pagination::meta($page, $perPage, $result['total']),
        ]);
    }

    #[Route('/api/admin/people/{id}', name: 'admin_people_show', methods: ['GET'], priority: -10)]
    public function show(string $id): JsonResponse
    {
        $person = $this->findPersonOrFail($id);

        return new JsonResponse(['data' => $this->normalizePersonDetail($person)]);
    }

    #[Route('/api/admin/people/{id}', name: 'admin_people_update', methods: ['PATCH'], priority: -10)]
    public function update(string $id, Request $request): JsonResponse
    {
        $person = $this->findPersonOrFail($id);
        $payload = $this->decode($request);

        if (\array_key_exists('name', $payload)) {
            $name = $payload['name'];
            if (null === $name || ('' === trim((string) $name))) {
                $person->setName(null);
                $person->setIsNamed(false);
            } elseif (\is_string($name)) {
                $person->setName(trim($name));
                $person->setIsNamed(true);
            } else {
                throw new BadRequestHttpException('name must be a string or null.');
            }
        }

        if (\array_key_exists('avatarFaceId', $payload)) {
            $avatarFaceId = $payload['avatarFaceId'];
            if (null === $avatarFaceId || '' === $avatarFaceId) {
                $person->setAvatarFace(null);
            } elseif (\is_string($avatarFaceId)) {
                $face = $this->findFaceOrFail($avatarFaceId);
                if ($face->getPerson()?->getId()?->equals($person->getId()) !== true) {
                    throw new BadRequestHttpException('avatarFaceId must belong to this person.');
                }
                $this->clearCustomAvatar($person);
                $person->setAvatarFace($face);
            } else {
                throw new BadRequestHttpException('avatarFaceId must be a string or null.');
            }
        }

        $this->em->flush();

        return new JsonResponse(['data' => $this->normalizePersonDetail($person)]);
    }

    #[Route('/api/admin/people/{id}/avatar', name: 'admin_people_avatar_upload', methods: ['POST'])]
    public function uploadAvatar(string $id, Request $request): JsonResponse
    {
        $person = $this->findPersonOrFail($id);

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            throw new BadRequestHttpException('A "file" upload is required.');
        }
        if (!$file->isValid()) {
            throw new BadRequestHttpException('Upload failed: '.$file->getErrorMessage());
        }
        if (!\in_array($file->getMimeType(), self::ALLOWED_AVATAR_MIME_TYPES, true)) {
            throw new BadRequestHttpException('Unsupported file type; expected JPEG, PNG, or WebP.');
        }

        $this->clearCustomAvatar($person);
        $person->setAvatarFace(null);

        $relativePath = $this->storage->storePersonAvatar($file, (string) $person->getId());
        $person->setAvatarPath($relativePath);
        $this->em->flush();

        return new JsonResponse(['data' => $this->normalizePersonDetail($person)]);
    }

    #[Route('/api/admin/people/{id}/avatar', name: 'admin_people_avatar_delete', methods: ['DELETE'])]
    public function deleteAvatar(string $id): JsonResponse
    {
        $person = $this->findPersonOrFail($id);
        $this->clearCustomAvatar($person);
        $this->em->flush();

        return new JsonResponse(['data' => $this->normalizePersonDetail($person)]);
    }

    #[Route('/api/admin/people/{id}/name', name: 'admin_people_name', methods: ['POST'])]
    public function name(string $id, Request $request): JsonResponse
    {
        $person = $this->findPersonOrFail($id);
        $payload = $this->decode($request);

        $name = $payload['name'] ?? null;
        if (!\is_string($name) || '' === trim($name)) {
            throw new BadRequestHttpException('name is required.');
        }

        $person->setName(trim($name));
        $person->setIsNamed(true);
        $this->em->flush();

        return new JsonResponse(['data' => $this->normalizePerson($person)]);
    }

    #[Route('/api/admin/people/{id}/merge', name: 'admin_people_merge', methods: ['POST'])]
    public function merge(string $id, Request $request): JsonResponse
    {
        $source = $this->findPersonOrFail($id);
        $payload = $this->decode($request);

        $targetId = $payload['targetPersonId'] ?? null;
        if (!\is_string($targetId)) {
            throw new BadRequestHttpException('targetPersonId is required.');
        }

        $target = $this->findPersonOrFail($targetId);

        $this->merger->merge($source, $target);

        return new JsonResponse(['data' => $this->normalizePerson($target)]);
    }

    #[Route('/api/admin/people/{id}', name: 'admin_people_discard', methods: ['DELETE'], priority: -10)]
    public function discard(string $id): JsonResponse
    {
        $person = $this->findPersonOrFail($id);
        $this->personDeleter->delete($person);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/admin/photos/{photoId}/people', name: 'admin_photos_people_add', methods: ['POST'])]
    public function addToPhoto(string $photoId, Request $request): JsonResponse
    {
        $photo = $this->findPhotoOrFail($photoId);
        $payload = $this->decode($request);

        $person = $this->resolvePersonForPhotoAttach($payload);

        $existing = $this->faces->findOneByPhotoAndPerson($photo, $person);
        if (null !== $existing) {
            return new JsonResponse(['data' => $this->normalizeFace($existing)], Response::HTTP_OK);
        }

        $face = new Face($photo);
        $face->setPerson($person);
        $this->em->persist($face);
        $this->em->flush();

        return new JsonResponse(['data' => $this->normalizeFace($face)], Response::HTTP_CREATED);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolvePersonForPhotoAttach(array $payload): Person
    {
        $personId = $payload['personId'] ?? null;
        if (\is_string($personId) && '' !== $personId) {
            return $this->findPersonOrFail($personId);
        }

        $name = $payload['name'] ?? null;
        if (\is_string($name) && '' !== trim($name)) {
            $trimmed = trim($name);
            $existing = $this->people->findOneNamedByName($trimmed);
            if (null !== $existing) {
                return $existing;
            }

            $person = new Person();
            $person->setName($trimmed);
            $person->setIsNamed(true);
            $this->em->persist($person);

            return $person;
        }

        throw new BadRequestHttpException('personId or name is required.');
    }

    #[Route('/api/admin/photos/{photoId}/people/{personId}', name: 'admin_photos_people_remove', methods: ['DELETE'])]
    public function removeFromPhoto(string $photoId, string $personId): Response
    {
        $photo = $this->findPhotoOrFail($photoId);
        $person = $this->findPersonOrFail($personId);

        foreach ($this->faces->findByPhotoAndPerson($photo, $person) as $face) {
            $this->em->remove($face);
        }
        $this->em->flush();

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    private function findPersonOrFail(string $id): Person
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

    private function findPhotoOrFail(string $id): \App\Entity\Photo
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

    private function clearCustomAvatar(Person $person): void
    {
        $this->storage->deleteRelative($person->getAvatarPath());
        $person->setAvatarPath(null);
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
    private function normalizeCluster(Person $person): array
    {
        $avatar = $person->getAvatarFace();

        return [
            'id' => (string) $person->getId(),
            'faceCount' => \count($person->getFaces()),
            'faces' => array_map($this->normalizeFace(...), $person->getFaces()->toArray()),
            'avatarFaceId' => $avatar ? (string) $avatar->getId() : null,
            'avatarCropPath' => $person->getEffectiveAvatarPath(),
        ];
    }

    /**
     * @param array{faceCount: int, fallbackCropPath: ?string}|null $faceSummary
     *
     * @return array<string, mixed>
     */
    private function normalizePerson(Person $person, ?array $faceSummary = null): array
    {
        $avatar = $person->getAvatarFace();
        if (null !== $faceSummary) {
            $faceCount = $faceSummary['faceCount'];
            $avatarCropPath = $person->getAvatarPath()
                ?? $avatar?->getCropPath()
                ?? $faceSummary['fallbackCropPath'];
        } else {
            $faceCount = \count($person->getFaces());
            $avatarCropPath = $person->getEffectiveAvatarPath();
        }

        return [
            'id' => (string) $person->getId(),
            'name' => $person->getName(),
            'isNamed' => $person->isNamed(),
            'faceCount' => $faceCount,
            'avatarFaceId' => $avatar ? (string) $avatar->getId() : null,
            'avatarCropPath' => $avatarCropPath,
        ];
    }

    /** @return array<string, mixed> */
    private function normalizePersonDetail(Person $person): array
    {
        return [
            ...$this->normalizePerson($person),
            'faces' => array_map($this->normalizeFace(...), $person->getFaces()->toArray()),
            'hasCustomAvatar' => null !== $person->getAvatarPath() && '' !== $person->getAvatarPath(),
        ];
    }

    /** @return array<string, mixed> */
    private function normalizeFace(Face $face): array
    {
        return [
            'id' => (string) $face->getId(),
            'photoId' => $face->getPhoto() ? (string) $face->getPhoto()->getId() : null,
            'personId' => $face->getPerson()?->getId() ? (string) $face->getPerson()->getId() : null,
            'cropPath' => $face->getCropPath(),
            'hasEmbedding' => $face->hasEmbedding(),
        ];
    }
}
