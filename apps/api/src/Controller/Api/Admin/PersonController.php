<?php

namespace App\Controller\Api\Admin;

use App\Entity\Face;
use App\Entity\Person;
use App\Repository\FaceRepository;
use App\Repository\PersonRepository;
use App\Repository\PhotoRepository;
use App\Service\PersonMerger;
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
class PersonController
{
    public function __construct(
        private readonly PersonRepository $people,
        private readonly PhotoRepository $photos,
        private readonly FaceRepository $faces,
        private readonly PersonMerger $merger,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/api/admin/people/unnamed', name: 'admin_people_unnamed', methods: ['GET'])]
    public function unnamed(): JsonResponse
    {
        $people = $this->people->findUnnamed();

        return new JsonResponse(['data' => array_map($this->normalizeCluster(...), $people)]);
    }

    /** Named people search, used by the photo-edit "add person" picker. */
    #[Route('/api/admin/people', name: 'admin_people_search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $q = $request->query->get('q');
        $people = $this->people->searchNamed(\is_string($q) ? $q : null);

        return new JsonResponse(['data' => array_map($this->normalizePerson(...), $people)]);
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

    #[Route('/api/admin/people/{id}', name: 'admin_people_discard', methods: ['DELETE'])]
    public function discard(string $id): JsonResponse
    {
        $person = $this->findPersonOrFail($id);

        if ($person->isNamed()) {
            throw new ConflictHttpException('Only unnamed clusters can be discarded.');
        }

        $person->setAvatarFace(null);
        $this->em->flush();

        foreach ($person->getFaces() as $face) {
            $this->em->remove($face);
        }
        $this->em->remove($person);
        $this->em->flush();

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
        ];
    }

    /** @return array<string, mixed> */
    private function normalizePerson(Person $person): array
    {
        return [
            'id' => (string) $person->getId(),
            'name' => $person->getName(),
            'isNamed' => $person->isNamed(),
            'faceCount' => \count($person->getFaces()),
        ];
    }

    /** @return array<string, mixed> */
    private function normalizeFace(Face $face): array
    {
        return [
            'id' => (string) $face->getId(),
            'photoId' => (string) $face->getPhoto()->getId(),
            'personId' => $face->getPerson()?->getId() ? (string) $face->getPerson()->getId() : null,
            'cropPath' => $face->getCropPath(),
            'hasEmbedding' => $face->hasEmbedding(),
        ];
    }
}
