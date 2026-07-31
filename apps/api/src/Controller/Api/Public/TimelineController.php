<?php

namespace App\Controller\Api\Public;

use App\Entity\Photo;
use App\Http\Pagination;
use App\Repository\PhotoRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
#[Route('/api/timeline')]
class TimelineController
{
    private const DEFAULT_PER_PAGE = 48;

    public function __construct(
        private readonly PhotoRepository $photos,
    ) {
    }

    #[Route('/months', name: 'public_timeline_months', methods: ['GET'])]
    public function months(): JsonResponse
    {
        return new JsonResponse([
            'data' => $this->photos->findPublicTimelineMonths(),
        ]);
    }

    #[Route('/photos', name: 'public_timeline_photos', methods: ['GET'])]
    public function photos(Request $request): JsonResponse
    {
        $year = (int) $request->query->get('year', 0);
        $month = (int) $request->query->get('month', 0);
        if ($year < 1 || $month < 1 || $month > 12) {
            throw new BadRequestHttpException('year and month are required.');
        }

        $page = Pagination::page($request);
        $perPage = Pagination::perPage($request, self::DEFAULT_PER_PAGE);
        $result = $this->photos->findPublicTimelinePhotosPaginated($year, $month, $page, $perPage);

        return new JsonResponse([
            'data' => array_map($this->normalizePhoto(...), $result['items']),
            'meta' => Pagination::meta($page, $perPage, $result['total']),
        ]);
    }

    /** @return array<string, mixed> */
    private function normalizePhoto(Photo $photo): array
    {
        return [
            'id' => (string) $photo->getId(),
            'title' => $photo->getTitle(),
            'avifPath' => $photo->getAvifPath(),
            'thumbPaths' => $photo->getThumbPaths(),
            'originalPath' => $photo->getOriginalPath(),
            'viewCount' => $photo->getViewCount(),
        ];
    }
}
