<?php

namespace App\Controller\Api\Admin;

use App\Entity\FaceGalleryScan;
use App\Entity\FaceGalleryScanMatch;
use App\Http\Pagination;
use App\Entity\Person;
use App\Repository\FaceGalleryScanMatchRepository;
use App\Repository\FaceGalleryScanRepository;
use App\Repository\PersonRepository;
use App\Repository\PhotoRepository;
use App\Service\FaceEmbeddingClientInterface;
use App\Service\FaceGalleryScanConfirmer;
use App\Service\FaceGalleryScanDeleter;
use App\Service\FaceGalleryScanEnqueuer;
use App\Service\MediaStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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
class FaceGalleryScanController
{
    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        private readonly FaceGalleryScanRepository $scans,
        private readonly FaceGalleryScanMatchRepository $matches,
        private readonly PersonRepository $people,
        private readonly PhotoRepository $photos,
        private readonly FaceGalleryScanEnqueuer $enqueuer,
        private readonly FaceGalleryScanConfirmer $confirmer,
        private readonly FaceGalleryScanDeleter $deleter,
        private readonly FaceEmbeddingClientInterface $embeddingClient,
        private readonly MediaStorage $storage,
        private readonly EntityManagerInterface $em,
        #[Autowire('%env(float:FACE_MATCH_THRESHOLD)%')]
        private readonly float $defaultThreshold,
    ) {
    }

    #[Route('/api/admin/people/face-scans', name: 'admin_face_scans_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
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

        try {
            $embedding = $this->embeddingClient->embedUpload($file);
        } catch (\RuntimeException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }

        $scan = new FaceGalleryScan($embedding, $this->defaultThreshold);
        $this->em->persist($scan);
        $this->em->flush();

        $scan->setReferenceCropPath($this->storage->storeFaceScanReference($file, (string) $scan->getId()));

        return $this->startScan($scan);
    }

    #[Route('/api/admin/people/{id}/face-scans', name: 'admin_people_face_scans_from_person', methods: ['POST'])]
    public function createFromPerson(string $id): JsonResponse
    {
        $person = $this->findActivePersonOrFail($id);
        $source = $this->referenceFaceForScan($person);
        if (null === $source) {
            throw new BadRequestHttpException('Person has no face embedding to scan with.');
        }

        $embedding = $source->getEmbedding();
        if (!\is_array($embedding) || [] === $embedding) {
            throw new BadRequestHttpException('Person has no face embedding to scan with.');
        }

        $scan = new FaceGalleryScan($embedding, $this->defaultThreshold);
        $scan->setTargetPerson($person);
        $this->em->persist($scan);
        $this->em->flush();

        $scan->setReferenceCropPath(
            $this->storage->copyToFaceScanReference($source->getCropPath(), (string) $scan->getId()),
        );

        return $this->startScan($scan);
    }

    #[Route('/api/admin/people/face-scans', name: 'admin_face_scans_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $page = Pagination::page($request);
        $perPage = Pagination::perPage($request, 20, 50);
        $targetPersonId = $request->query->get('targetPersonId');
        $result = $this->scans->searchPaginated(
            $page,
            $perPage,
            \is_string($targetPersonId) && '' !== $targetPersonId ? $targetPersonId : null,
        );

        return new JsonResponse([
            'data' => array_map($this->normalizeScan(...), $result['items']),
            'meta' => Pagination::meta($page, $perPage, $result['total']),
        ]);
    }

    #[Route('/api/admin/people/face-scans/{id}', name: 'admin_face_scans_show', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        $scan = $this->findScanOrFail($id);
        if (!$scan->isTerminal()) {
            $this->enqueuer->enqueueNextBatch($scan);
            $this->em->refresh($scan);
        }

        return new JsonResponse(['data' => $this->normalizeScanDetail($scan)]);
    }

    #[Route('/api/admin/people/face-scans/{id}/cancel', name: 'admin_face_scans_cancel', methods: ['POST'])]
    public function cancel(string $id): JsonResponse
    {
        $scan = $this->findScanOrFail($id);
        if ($scan->isTerminal()) {
            return new JsonResponse(['data' => $this->normalizeScan($scan)]);
        }

        $scan->setStatus(FaceGalleryScan::STATUS_CANCELLED);
        $this->em->flush();

        return new JsonResponse(['data' => $this->normalizeScan($scan)]);
    }

    #[Route('/api/admin/people/face-scans/{id}', name: 'admin_face_scans_delete', methods: ['DELETE'])]
    public function delete(string $id): Response
    {
        $scan = $this->findScanOrFail($id);
        $this->deleter->delete($scan);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/admin/people/face-scans/{id}/matches/{matchId}', name: 'admin_face_scans_match_patch', methods: ['PATCH'])]
    public function patchMatch(string $id, string $matchId, Request $request): JsonResponse
    {
        $scan = $this->findScanOrFail($id);
        $match = $this->findMatchOrFail($scan, $matchId);
        $payload = $this->decode($request);

        if (!\array_key_exists('selected', $payload)) {
            throw new BadRequestHttpException('selected is required.');
        }
        if (!\is_bool($payload['selected'])) {
            throw new BadRequestHttpException('selected must be a boolean.');
        }

        $match->setSelected($payload['selected']);
        $this->em->flush();

        return new JsonResponse(['data' => $this->normalizeMatch($match)]);
    }

    #[Route('/api/admin/people/face-scans/{id}/confirm', name: 'admin_face_scans_confirm', methods: ['POST'])]
    public function confirm(string $id, Request $request): JsonResponse
    {
        $scan = $this->findScanOrFail($id);
        $payload = $this->decode($request);
        $name = $payload['name'] ?? null;
        $person = $this->confirmer->confirm($scan, \is_string($name) ? $name : null);

        return new JsonResponse([
            'data' => [
                'personId' => (string) $person->getId(),
            ],
        ]);
    }

    private function findScanOrFail(string $id): FaceGalleryScan
    {
        try {
            $uuid = Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Scan not found.');
        }

        $scan = $this->scans->findOneById($uuid);
        if (null === $scan) {
            throw new NotFoundHttpException('Scan not found.');
        }

        return $scan;
    }

    private function findMatchOrFail(FaceGalleryScan $scan, string $matchId): FaceGalleryScanMatch
    {
        try {
            $uuid = Uuid::fromString($matchId);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Match not found.');
        }

        $match = $this->matches->findOneForScan($scan->getId(), $uuid);
        if (null === $match) {
            throw new NotFoundHttpException('Match not found.');
        }

        return $match;
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
    private function normalizeScan(FaceGalleryScan $scan): array
    {
        return [
            'id' => (string) $scan->getId(),
            'status' => $scan->getStatus(),
            'referenceCropPath' => $scan->getReferenceCropPath(),
            'threshold' => $scan->getThreshold(),
            'totalPhotos' => $scan->getTotalPhotos(),
            'enqueuedPhotos' => $scan->getEnqueuedPhotos(),
            'processedPhotos' => $scan->getProcessedPhotos(),
            'matchedPhotos' => $scan->getMatchedPhotos(),
            'error' => $scan->getError(),
            'targetPersonId' => $scan->getTargetPerson() ? (string) $scan->getTargetPerson()->getId() : null,
            'createdAt' => $scan->getCreatedAt()->format(\DATE_ATOM),
            'updatedAt' => $scan->getUpdatedAt()->format(\DATE_ATOM),
        ];
    }

    private function startScan(FaceGalleryScan $scan): JsonResponse
    {
        $scan->setTotalPhotos($this->photos->countWithAvif());
        $scan->setStatus(FaceGalleryScan::STATUS_RUNNING);
        $this->em->flush();

        if (0 === $scan->getTotalPhotos()) {
            $scan->setStatus(FaceGalleryScan::STATUS_DONE);
            $this->em->flush();
        } else {
            $this->enqueuer->enqueueNextBatch($scan);
        }

        return new JsonResponse(['data' => $this->normalizeScan($scan)], JsonResponse::HTTP_CREATED);
    }

    private function findActivePersonOrFail(string $id): Person
    {
        try {
            $uuid = Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Person not found.');
        }

        $person = $this->people->findActive($uuid);
        if (null === $person) {
            throw new NotFoundHttpException('Person not found.');
        }

        return $person;
    }

    private function referenceFaceForScan(Person $person): ?\App\Entity\Face
    {
        $avatar = $person->getAvatarFace();
        if (null !== $avatar && $avatar->hasEmbedding()) {
            return $avatar;
        }
        foreach ($person->getFaces() as $face) {
            if ($face->hasEmbedding()) {
                return $face;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function normalizeScanDetail(FaceGalleryScan $scan): array
    {
        return [
            ...$this->normalizeScan($scan),
            'matches' => array_map(
                $this->normalizeMatch(...),
                $this->matches->findByScanOrdered($scan),
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function normalizeMatch(FaceGalleryScanMatch $match): array
    {
        $photo = $match->getPhoto();

        return [
            'id' => (string) $match->getId(),
            'photoId' => (string) $photo->getId(),
            'photoFilename' => $photo->getFilename(),
            'photoTitle' => $photo->getTitle(),
            'distance' => $match->getDistance(),
            'cropPath' => $match->getCropPath(),
            'selected' => $match->isSelected(),
        ];
    }
}
