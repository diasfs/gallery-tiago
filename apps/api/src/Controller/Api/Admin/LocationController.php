<?php

namespace App\Controller\Api\Admin;

use App\Entity\Location;
use App\Repository\LocationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
#[Route('/api/admin/locations')]
class LocationController
{
    public function __construct(
        private readonly LocationRepository $locations,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'admin_locations_search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $q = $request->query->get('q');
        $locations = array_map($this->normalize(...), $this->locations->search(\is_string($q) ? $q : null));

        return new JsonResponse(['data' => $locations]);
    }

    #[Route('', name: 'admin_locations_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $payload = $this->decode($request);

        $name = $payload['name'] ?? null;
        if (!\is_string($name) || '' === $name) {
            throw new BadRequestHttpException('name is required.');
        }

        $location = new Location($name);
        $this->applyPayload($location, $payload);

        $this->em->persist($location);
        $this->em->flush();

        return new JsonResponse(['data' => $this->normalize($location)], Response::HTTP_CREATED);
    }

    /** @param array<string, mixed> $payload */
    private function applyPayload(Location $location, array $payload): void
    {
        if (isset($payload['city'])) {
            $location->setCity(\is_string($payload['city']) ? $payload['city'] : null);
        }
        if (isset($payload['country'])) {
            $location->setCountry(\is_string($payload['country']) ? $payload['country'] : null);
        }
        if (\array_key_exists('latitude', $payload)) {
            if (null !== $payload['latitude'] && !\is_numeric($payload['latitude'])) {
                throw new BadRequestHttpException('latitude must be numeric.');
            }
            $location->setLatitude(null === $payload['latitude'] ? null : (float) $payload['latitude']);
        }
        if (\array_key_exists('longitude', $payload)) {
            if (null !== $payload['longitude'] && !\is_numeric($payload['longitude'])) {
                throw new BadRequestHttpException('longitude must be numeric.');
            }
            $location->setLongitude(null === $payload['longitude'] ? null : (float) $payload['longitude']);
        }
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
    private function normalize(Location $location): array
    {
        return [
            'id' => (string) $location->getId(),
            'name' => $location->getName(),
            'city' => $location->getCity(),
            'country' => $location->getCountry(),
            'latitude' => $location->getLatitude(),
            'longitude' => $location->getLongitude(),
        ];
    }
}
