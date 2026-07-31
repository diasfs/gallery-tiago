<?php

namespace App\Controller\Api\Public;

use App\Service\ProcessingSettingsReader;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
#[Route('/api/site-config')]
final class SiteConfigController
{
    public function __construct(
        private readonly ProcessingSettingsReader $settings,
    ) {
    }

    #[Route('', name: 'public_site_config', methods: ['GET'])]
    public function show(): JsonResponse
    {
        return new JsonResponse([
            'data' => [
                'albumPhotoLayout' => $this->settings->getAlbumPhotoLayout()->value,
            ],
        ]);
    }
}
