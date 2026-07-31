<?php

namespace App\Service;

use App\Entity\Album;
use App\Entity\Photo;
use App\Entity\Tag;
use App\Enum\AlbumVisibility;
use App\Repository\PhotoRepository;

final class PhotoPublicNormalizer
{
    public function __construct(
        private readonly PhotoRepository $photos,
    ) {
    }

    /** @return array<string, mixed> */
    public function summary(Photo $photo): array
    {
        $album = $photo->getAlbum();

        return [
            'id' => (string) $photo->getId(),
            'albumId' => (string) $album->getId(),
            'albumSlug' => $album->getSlug(),
            'filename' => $photo->getFilename(),
            'title' => $photo->getTitle(),
            'avifPath' => $photo->getAvifPath(),
            'thumbPaths' => $photo->getThumbPaths(),
            'originalPath' => $photo->getOriginalPath(),
            'width' => $photo->getWidth(),
            'height' => $photo->getHeight(),
            'viewCount' => $photo->getViewCount(),
        ];
    }

    /** @return array<string, mixed> */
    public function detail(Photo $photo): array
    {
        [$prevId, $nextId, $prevFilename, $nextFilename] = $this->adjacent($photo);
        $album = $photo->getAlbum();

        return $this->summary($photo) + [
            'albumTitle' => $album->getTitle(),
            'albumAncestors' => $this->albumAncestors($album),
            'width' => $photo->getWidth(),
            'height' => $photo->getHeight(),
            'tags' => array_map($this->normalizeTag(...), $photo->getTags()->toArray()),
            'people' => $this->normalizePeople($photo),
            'prevId' => $prevId,
            'nextId' => $nextId,
            'prevFilename' => $prevFilename,
            'nextFilename' => $nextFilename,
        ];
    }

    /** @return array<string, mixed> */
    public function similar(Photo $photo): array
    {
        return [
            'id' => (string) $photo->getId(),
            'albumSlug' => $photo->getAlbum()->getSlug(),
            'filename' => $photo->getFilename(),
            'title' => $photo->getTitle(),
            'avifPath' => $photo->getAvifPath(),
            'thumbPaths' => $photo->getThumbPaths(),
            'originalPath' => $photo->getOriginalPath(),
            'viewCount' => $photo->getViewCount(),
        ];
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: ?string, 3: ?string}
     */
    private function adjacent(Photo $photo): array
    {
        $siblings = $this->photos->findByAlbum($photo->getAlbum());
        $index = array_search($photo->getId()->toRfc4122(), array_map(
            static fn (Photo $p): string => $p->getId()->toRfc4122(),
            $siblings,
        ), true);

        if (false === $index) {
            return [null, null, null, null];
        }

        $prev = $index > 0 ? $siblings[$index - 1] : null;
        $next = $index < \count($siblings) - 1 ? $siblings[$index + 1] : null;

        return [
            $prev?->getId()->toRfc4122(),
            $next?->getId()->toRfc4122(),
            $prev?->getFilename(),
            $next?->getFilename(),
        ];
    }

    /**
     * @return array<int, array{slug: string, title: string}>
     */
    private function albumAncestors(Album $album): array
    {
        $chain = [];
        $current = $album->getParent();
        while ($this->isVisible($current)) {
            $chain[] = ['slug' => $current->getSlug(), 'title' => $current->getTitle()];
            $current = $current->getParent();
        }

        return array_reverse($chain);
    }

    private function isVisible(?Album $album): bool
    {
        return null !== $album && AlbumVisibility::Private !== $album->getVisibility();
    }

    /** @return array<int, array{id: string, name: ?string, avatarCropPath: ?string}> */
    private function normalizePeople(Photo $photo): array
    {
        $seen = [];
        $people = [];
        foreach ($photo->getFaces() as $face) {
            $person = $face->getPerson();
            if (null === $person) {
                continue;
            }
            $personId = (string) $person->getId();
            if (isset($seen[$personId])) {
                continue;
            }
            $seen[$personId] = true;
            $people[] = [
                'id' => $personId,
                'name' => $person->getName(),
                'avatarCropPath' => $person->getEffectiveAvatarPath(),
            ];
        }

        return $people;
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
}
