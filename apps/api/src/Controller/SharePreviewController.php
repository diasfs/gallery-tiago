<?php

namespace App\Controller;

use App\Entity\Album;
use App\Entity\Photo;
use App\Repository\AlbumRepository;
use App\Repository\PhotoRepository;
use App\Service\PublicPhotoDisplay;
use App\Service\PublicSiteUrlBuilder;
use App\Service\SharePreview;
use App\Service\SharePreviewRenderer;
use App\Service\SocialCrawlerDetector;
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
        private readonly PublicPhotoDisplay $photoDisplay,
        private readonly PublicSiteUrlBuilder $siteUrls,
        private readonly SharePreviewRenderer $renderer,
    ) {
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

    private function previewForPhoto(Photo $photo, Request $request): SharePreview
    {
        $album = $photo->getAlbum();
        $title = trim((string) $photo->getTitle());
        if ('' === $title) {
            $title = 'Foto sem título';
        }

        $image = $this->previewImageMeta($photo, $request);

        return new SharePreview(
            title: sprintf('%s · Gallery', $title),
            description: sprintf('%s — %s', $title, $album->getTitle()),
            canonicalUrl: $this->siteUrls->page('photos/'.$photo->getId()->toRfc4122(), $request),
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
            canonicalUrl: $this->siteUrls->page('albums/'.$album->getSlug(), $request),
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
