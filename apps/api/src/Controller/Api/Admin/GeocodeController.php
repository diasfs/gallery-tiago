<?php

namespace App\Controller\Api\Admin;

use App\Service\GeocoderInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
#[Route('/api/admin/geocode')]
class GeocodeController
{
    public function __construct(
        private readonly GeocoderInterface $geocoder,
    ) {
    }

    #[Route('', name: 'admin_geocode_search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $q = $request->query->get('q');
        if (!\is_string($q) || mb_strlen(trim($q)) < 2) {
            throw new BadRequestHttpException('q must be at least 2 characters.');
        }

        return new JsonResponse(['data' => $this->geocoder->search($q)]);
    }

    #[Route('/reverse', name: 'admin_geocode_reverse', methods: ['GET'])]
    public function reverse(Request $request): JsonResponse
    {
        $lat = $request->query->get('lat');
        $lon = $request->query->get('lon');
        if (!\is_numeric($lat) || !\is_numeric($lon)) {
            throw new BadRequestHttpException('lat and lon must be numeric.');
        }

        $result = $this->geocoder->reverse((float) $lat, (float) $lon);
        if (null === $result) {
            throw new NotFoundHttpException('No place found for these coordinates.');
        }

        return new JsonResponse(['data' => $result]);
    }
}
