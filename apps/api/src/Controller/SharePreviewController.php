<?php

namespace App\Controller;

use App\Entity\Album;
use App\Entity\Location;
use App\Entity\Photo;
use App\Repository\AlbumRepository;
use App\Repository\LocationRepository;
use App\Repository\PersonRepository;
use App\Repository\PhotoRepository;
use App\Repository\TagRepository;
use App\Service\PublicPhotoDisplay;
use App\Service\PublicSiteUrlBuilder;
use App\Service\SharePreview;
use App\Service\SharePreviewRenderer;
use App\Service\SocialCrawlerDetector;
use App\Support\ReservedAlbumSlugs;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[AsController]
final class SharePreviewController
{
    public function __construct(
        private readonly PhotoRepository $photos,
        private readonly AlbumRepository $albums,
        private readonly PersonRepository $people,
        private readonly TagRepository $tags,
        private readonly LocationRepository $locations,
        private readonly PublicPhotoDisplay $photoDisplay,
        private readonly PublicSiteUrlBuilder $siteUrls,
        private readonly SharePreviewRenderer $renderer,
    ) {
    }

    #[Route(
        '/{albumSlug}/{filename}',
        name: 'share_preview_photo_root',
        methods: ['GET'],
        requirements: [
            'albumSlug' => ReservedAlbumSlugs::ROUTE_SLUG_PATTERN,
            'filename' => ReservedAlbumSlugs::FILENAME_PATTERN,
        ],
        priority: 20,
    )]
    public function photoRoot(Request $request, string $albumSlug, string $filename): Response
    {
        $photo = $this->photos->findVisibleByAlbumSlugAndFilename($albumSlug, rawurldecode($filename));
        if (null === $photo) {
            throw new NotFoundHttpException('Photo not found.');
        }

        return $this->respond($request, $this->previewForPhoto($photo, $request));
    }

    #[Route('/', name: 'share_preview_home', methods: ['GET'], priority: 40)]
    public function home(Request $request): Response
    {
        return $this->respond($request, $this->previewForStaticPage('Gallery', 'Galeria de fotos', $request, ''));
    }

    #[Route(
        '/{page}',
        name: 'share_preview_static_page',
        methods: ['GET'],
        requirements: ['page' => 'search|map|timeline|memories|popular|tags'],
        priority: 30,
    )]
    public function staticPage(Request $request, string $page): Response
    {
        $titles = [
            'search' => 'Busca',
            'map' => 'Mapa',
            'timeline' => 'Linha do tempo',
            'memories' => 'Memórias',
            'popular' => 'Populares',
            'tags' => 'Tags',
        ];

        return $this->respond(
            $request,
            $this->previewForStaticPage(sprintf('%s · Gallery', $titles[$page]), $titles[$page], $request, $page),
        );
    }

    #[Route('/people/{id}', name: 'share_preview_person', methods: ['GET'])]
    public function person(Request $request, string $id): Response
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

        $cover = $this->photos->findVisibleByPersonIdPaginated($uuid, 1, 1)['items'][0] ?? null;
        if (null === $cover) {
            throw new NotFoundHttpException('Person not found.');
        }

        $image = $this->previewImageMeta($cover, $request);

        return $this->respond(
            $request,
            new SharePreview(
                title: sprintf('%s · Gallery', $person->getName()),
                description: sprintf('Fotos de %s', $person->getName()),
                canonicalUrl: $this->siteUrls->page('people/'.$id, $request),
                imageUrl: $image['imageUrl'] ?? null,
                imageType: $image['imageType'] ?? null,
                imageWidth: $image['imageWidth'] ?? null,
                imageHeight: $image['imageHeight'] ?? null,
            ),
        );
    }

    #[Route('/tags/{slug}', name: 'share_preview_tag', methods: ['GET'])]
    public function tag(Request $request, string $slug): Response
    {
        $tag = $this->tags->findOneBy(['slug' => $slug]);
        if (null === $tag) {
            throw new NotFoundHttpException('Tag not found.');
        }

        $cover = $this->photos->findVisibleByTagSlugPaginated($slug, 1, 1)['items'][0] ?? null;
        if (null === $cover) {
            throw new NotFoundHttpException('Tag not found.');
        }

        $image = $this->previewImageMeta($cover, $request);

        return $this->respond(
            $request,
            new SharePreview(
                title: sprintf('%s · Gallery', $tag->getName()),
                description: sprintf('Fotos com a tag %s', $tag->getName()),
                canonicalUrl: $this->siteUrls->page('tags/'.$slug, $request),
                imageUrl: $image['imageUrl'] ?? null,
                imageType: $image['imageType'] ?? null,
                imageWidth: $image['imageWidth'] ?? null,
                imageHeight: $image['imageHeight'] ?? null,
            ),
        );
    }

    #[Route('/locations/{id}', name: 'share_preview_location', methods: ['GET'])]
    public function location(Request $request, string $id): Response
    {
        try {
            $uuid = Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Location not found.');
        }

        $location = $this->locations->find($uuid);
        if (null === $location) {
            throw new NotFoundHttpException('Location not found.');
        }

        $cover = $this->photos->findVisibleByLocationIdPaginated($uuid, 1, 1)['items'][0] ?? null;
        if (null === $cover) {
            throw new NotFoundHttpException('Location not found.');
        }

        $image = $this->previewImageMeta($cover, $request);

        return $this->respond(
            $request,
            new SharePreview(
                title: sprintf('%s · Gallery', $location->getName()),
                description: $this->locationDescription($location),
                canonicalUrl: $this->siteUrls->page('locations/'.$id, $request),
                imageUrl: $image['imageUrl'] ?? null,
                imageType: $image['imageType'] ?? null,
                imageWidth: $image['imageWidth'] ?? null,
                imageHeight: $image['imageHeight'] ?? null,
            ),
        );
    }

    #[Route(
        '/{slug}',
        name: 'share_preview_album_root',
        methods: ['GET'],
        requirements: ['slug' => ReservedAlbumSlugs::ROUTE_SLUG_PATTERN],
        priority: 10,
    )]
    public function albumRoot(Request $request, string $slug): Response
    {
        if (ReservedAlbumSlugs::isReserved($slug)) {
            throw new NotFoundHttpException('Album not found.');
        }

        $album = $this->albums->findVisibleBySlug($slug);
        if (null === $album) {
            throw new NotFoundHttpException('Album not found.');
        }

        return $this->respond($request, $this->previewForAlbum($album, $request));
    }

    #[Route('/photos/{id}', name: 'share_preview_photo', methods: ['GET'])]
    public function photo(Request $request, string $id): Response
    {
        try {
            $uuid = Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Photo not found.');
        }

        $photo = $this->photos->findVisibleById($uuid);
        if (null === $photo) {
            throw new NotFoundHttpException('Photo not found.');
        }

        return $this->respond($request, $this->previewForPhoto($photo, $request));
    }

    #[Route('/albums/{slug}', name: 'share_preview_album', methods: ['GET'])]
    public function album(Request $request, string $slug): Response
    {
        $album = $this->albums->findVisibleBySlug($slug);
        if (null === $album) {
            throw new NotFoundHttpException('Album not found.');
        }

        return $this->respond($request, $this->previewForAlbum($album, $request));
    }

    private function respond(Request $request, SharePreview $preview): Response
    {
        if (!SocialCrawlerDetector::isSocialCrawler($request->headers->get('User-Agent'))) {
            return new RedirectResponse($preview->canonicalUrl, Response::HTTP_FOUND);
        }

        return new Response(
            $this->renderer->render($preview),
            Response::HTTP_OK,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }

    private function previewForStaticPage(string $title, string $description, Request $request, string $path): SharePreview
    {
        return new SharePreview(
            title: $title,
            description: $description,
            canonicalUrl: $this->siteUrls->page($path, $request),
            imageUrl: null,
        );
    }

    private function locationDescription(Location $location): string
    {
        $parts = array_filter([$location->getCity(), $location->getCountry()]);
        if ([] === $parts) {
            return sprintf('Fotos em %s', $location->getName());
        }

        return sprintf('Fotos em %s — %s', $location->getName(), implode(', ', $parts));
    }

    private function previewForPhoto(Photo $photo, Request $request): SharePreview
    {
        $album = $photo->getAlbum();
        $title = trim((string) $photo->getTitle());
        if ('' === $title) {
            $title = 'Foto sem título';
        }

        $image = $this->previewImageMeta($photo, $request);
        $filename = $photo->getFilename() ?? 'photo';
        $canonical = $this->siteUrls->page($album->getSlug().'/'.rawurlencode($filename), $request);

        return new SharePreview(
            title: sprintf('%s · Gallery', $title),
            description: sprintf('%s — %s', $title, $album->getTitle()),
            canonicalUrl: $canonical,
            imageUrl: $image['imageUrl'],
            imageType: $image['imageType'],
            imageWidth: $image['imageWidth'],
            imageHeight: $image['imageHeight'],
        );
    }

    private function previewForAlbum(Album $album, Request $request): SharePreview
    {
        $description = trim((string) $album->getDescription());
        if ('' === $description) {
            $description = $album->getTitle();
        }

        $cover = $album->getCoverPhoto();
        $image = null !== $cover ? $this->previewImageMeta($cover, $request) : null;

        return new SharePreview(
            title: sprintf('%s · Gallery', $album->getTitle()),
            description: $description,
            canonicalUrl: $this->siteUrls->page($album->getSlug(), $request),
            imageUrl: $image['imageUrl'] ?? null,
            imageType: $image['imageType'] ?? null,
            imageWidth: $image['imageWidth'] ?? null,
            imageHeight: $image['imageHeight'] ?? null,
        );
    }

    /** @return array{imageUrl: ?string, imageType: ?string, imageWidth: ?int, imageHeight: ?int} */
    private function previewImageMeta(Photo $photo, Request $request): array
    {
        $relative = $this->photoDisplay->relativePath($photo);

        return [
            'imageUrl' => $this->siteUrls->media($relative, $request),
            'imageType' => null !== $relative ? $this->mimeTypeForPath($relative) : null,
            'imageWidth' => $photo->getWidth(),
            'imageHeight' => $photo->getHeight(),
        ];
    }

    private function mimeTypeForPath(string $path): ?string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'avif' => 'image/avif',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => null,
        };
    }
}
