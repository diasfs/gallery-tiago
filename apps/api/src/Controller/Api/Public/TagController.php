<?php

namespace App\Controller\Api\Public;

use App\Entity\Photo;
use App\Entity\Tag;
use App\Repository\PhotoRepository;
use App\Repository\TagRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
#[Route('/api/tags')]
class TagController
{
    public function __construct(
        private readonly TagRepository $tags,
        private readonly PhotoRepository $photos,
    ) {
    }

    #[Route('/{slug}', name: 'public_tags_show', methods: ['GET'])]
    public function show(string $slug): JsonResponse
    {
        $tag = $this->tags->findOneBy(['slug' => $slug]);
        if (null === $tag) {
            throw new NotFoundHttpException('Tag not found.');
        }

        $photos = $this->photos->findVisibleByTagSlug($slug);

        // Tag pages are only public when they have at least one publicly
        // reachable photo (design spec §11) — no existence leak otherwise.
        if ([] === $photos) {
            throw new NotFoundHttpException('Tag not found.');
        }

        return new JsonResponse(['data' => [
            'tag' => $this->normalizeTag($tag),
            'photos' => array_map($this->normalizePhoto(...), $photos),
        ]]);
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

    /**
     * Never include `originalPath` in the public payload.
     *
     * @return array<string, mixed>
     */
    private function normalizePhoto(Photo $photo): array
    {
        return [
            'id' => (string) $photo->getId(),
            'albumId' => (string) $photo->getAlbum()->getId(),
            'title' => $photo->getTitle(),
            'avifPath' => $photo->getAvifPath(),
            'thumbPaths' => $photo->getThumbPaths(),
        ];
    }
}
