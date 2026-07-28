<?php

namespace App\Controller\Api\Public;

use App\Entity\Photo;
use App\Entity\Tag;
use App\Http\Pagination;
use App\Repository\PhotoRepository;
use App\Repository\TagRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
#[Route('/api/tags')]
class TagController
{
    private const DEFAULT_PHOTO_PER_PAGE = 48;

    public function __construct(
        private readonly TagRepository $tags,
        private readonly PhotoRepository $photos,
    ) {
    }

    #[Route('', name: 'public_tags_search', methods: ['GET'], priority: 10)]
    public function search(Request $request): JsonResponse
    {
        $q = $request->query->get('q');
        $q = \is_string($q) ? $q : null;
        $tags = $this->tags->searchPublic($q);

        return new JsonResponse([
            'data' => array_map($this->normalizeTag(...), $tags),
        ]);
    }

    #[Route('/{slug}/photos', name: 'public_tags_photos', methods: ['GET'])]
    public function photos(string $slug, Request $request): JsonResponse
    {
        $tag = $this->findVisibleTagOrFail($slug);
        $page = Pagination::page($request);
        $perPage = Pagination::perPage($request, self::DEFAULT_PHOTO_PER_PAGE);
        $result = $this->photos->findVisibleByTagSlugPaginated($tag->getSlug(), $page, $perPage);

        return new JsonResponse([
            'data' => array_map($this->normalizePhoto(...), $result['items']),
            'meta' => Pagination::meta($page, $perPage, $result['total']),
        ]);
    }

    #[Route('/{slug}', name: 'public_tags_show', methods: ['GET'])]
    public function show(string $slug): JsonResponse
    {
        $tag = $this->findVisibleTagOrFail($slug);

        return new JsonResponse(['data' => [
            'tag' => $this->normalizeTag($tag),
        ]]);
    }

    private function findVisibleTagOrFail(string $slug): Tag
    {
        $tag = $this->tags->findOneBy(['slug' => $slug]);
        if (null === $tag) {
            throw new NotFoundHttpException('Tag not found.');
        }

        // Tag pages are only public when they have at least one publicly
        // reachable photo (design spec §11) — no existence leak otherwise.
        if (0 === $this->photos->countVisibleByTagSlug($slug)) {
            throw new NotFoundHttpException('Tag not found.');
        }

        return $tag;
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
     * Media-relative paths only. Never absolute filesystem paths.
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
            'originalPath' => $photo->getOriginalPath(),
        ];
    }
}
