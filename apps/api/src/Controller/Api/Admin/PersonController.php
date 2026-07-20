<?php

namespace App\Controller\Api\Admin;

use App\Entity\Face;
use App\Entity\Person;
use App\Repository\FaceRepository;
use App\Repository\PersonRepository;
use App\Repository\PhotoRepository;
use App\Service\PersonDeleter;
use App\Service\PersonMerger;
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
class PersonController
{
    public function __construct(
        private readonly PersonRepository $people,
        private readonly PhotoRepository $photos,
        private readonly FaceRepository $faces,
        private readonly PersonMerger $merger,
        private readonly PersonDeleter $personDeleter,
        private readonly EntityManagerInterface $em,
    ) {
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
        $limit = 'named' === $scope ? 20 : 200;
        $people = $this->people->search($scope, \is_string($q) ? $q : null, $limit);

        return new JsonResponse(['data' => array_map($this->normalizePerson(...), $people)]);
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
                $person->setAvatarFace($face);
            } else {
                throw new BadRequestHttpException('avatarFaceId must be a string or null.');
            }
        }

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

        $personId = $payload['personId'] ?? null;
        if (!\is_string($personId)) {
            throw new BadRequestHttpException('personId is required.');
        }

        $person = $this->findPersonOrFail($personId);

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
        return [
            'id' => (string) $person->getId(),
            'faceCount' => \count($person->getFaces()),
            'faces' => array_map($this->normalizeFace(...), $person->getFaces()->toArray()),
            'avatarFaceId' => $person->getAvatarFace() ? (string) $person->getAvatarFace()->getId() : null,
            'avatarCropPath' => $person->getAvatarFace()?->getCropPath(),
        ];
    }

    /** @return array<string, mixed> */
    private function normalizePerson(Person $person): array
    {
        $avatar = $person->getAvatarFace();

        return [
            'id' => (string) $person->getId(),
            'name' => $person->getName(),
            'isNamed' => $person->isNamed(),
            'faceCount' => \count($person->getFaces()),
            'avatarFaceId' => $avatar ? (string) $avatar->getId() : null,
            'avatarCropPath' => $avatar?->getCropPath(),
        ];
    }

    /** @return array<string, mixed> */
    private function normalizePersonDetail(Person $person): array
    {
        return [
            ...$this->normalizePerson($person),
            'faces' => array_map($this->normalizeFace(...), $person->getFaces()->toArray()),
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
