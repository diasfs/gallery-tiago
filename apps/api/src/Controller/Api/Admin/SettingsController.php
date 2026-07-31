<?php

namespace App\Controller\Api\Admin;

use App\Entity\ProcessingSettings;
use App\Enum\AlbumPhotoLayout;
use App\Enum\TagDetector;
use App\Repository\ProcessingSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
#[Route('/api/admin/settings')]
final class SettingsController
{
    public function __construct(
        private readonly ProcessingSettingsRepository $settings,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'admin_settings_get', methods: ['GET'])]
    public function get(): JsonResponse
    {
        return new JsonResponse(['data' => $this->normalize($this->settings->getSingleton())]);
    }

    #[Route('', name: 'admin_settings_update', methods: ['PUT'])]
    public function update(Request $request): JsonResponse
    {
        $payload = $this->decode($request);
        $row = $this->settings->getSingleton();

        if (\array_key_exists('facesEnabled', $payload)) {
            if (!\is_bool($payload['facesEnabled'])) {
                throw new BadRequestHttpException('facesEnabled must be a boolean.');
            }
            $row->setFacesEnabled($payload['facesEnabled']);
        }

        if (\array_key_exists('tagsEnabled', $payload)) {
            if (!\is_bool($payload['tagsEnabled'])) {
                throw new BadRequestHttpException('tagsEnabled must be a boolean.');
            }
            $row->setTagsEnabled($payload['tagsEnabled']);
        }

        if (\array_key_exists('tagDetector', $payload)) {
            $detector = $payload['tagDetector'];
            if (!\is_string($detector)) {
                throw new BadRequestHttpException('tagDetector must be a string.');
            }
            $resolved = TagDetector::tryFrom($detector);
            if (null === $resolved) {
                $allowed = implode(', ', array_map(static fn (TagDetector $c) => $c->value, TagDetector::cases()));
                throw new BadRequestHttpException(\sprintf('tagDetector must be one of: %s.', $allowed));
            }
            $row->setTagDetector($resolved);
        }

        if (\array_key_exists('albumPhotoLayout', $payload)) {
            $layout = $payload['albumPhotoLayout'];
            if (!\is_string($layout)) {
                throw new BadRequestHttpException('albumPhotoLayout must be a string.');
            }
            $resolved = AlbumPhotoLayout::tryFrom($layout);
            if (null === $resolved) {
                $allowed = implode(', ', array_map(static fn (AlbumPhotoLayout $c) => $c->value, AlbumPhotoLayout::cases()));
                throw new BadRequestHttpException(\sprintf('albumPhotoLayout must be one of: %s.', $allowed));
            }
            $row->setAlbumPhotoLayout($resolved);
        }

        $this->em->flush();

        return new JsonResponse(['data' => $this->normalize($row)]);
    }

    /** @return array<string, mixed> */
    private function normalize(ProcessingSettings $settings): array
    {
        return [
            'facesEnabled' => $settings->isFacesEnabled(),
            'tagsEnabled' => $settings->isTagsEnabled(),
            'tagDetector' => $settings->getTagDetector()->value,
            'albumPhotoLayout' => $settings->getAlbumPhotoLayout()->value,
        ];
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
}
