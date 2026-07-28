<?php

namespace App\Service\V3Import;

/**
 * In-memory v3 source for offline tests.
 */
final class ArrayV3GallerySource implements V3GallerySourceInterface
{
    /**
     * @param list<array{
     *     id_album: int,
     *     id_pai: int,
     *     titulo: string,
     *     descricao: ?string,
     *     url: string,
     *     ativo: string,
     *     ordem: int,
     *     data: ?string
     * }> $albums
     * @param list<array{
     *     id_foto: int,
     *     id_album: int,
     *     titulo: ?string,
     *     foto: string,
     *     ordem: int
     * }> $photos
     * @param list<array{id_album: int, foto: string, url: string}> $destaques
     */
    public function __construct(
        private readonly array $albums,
        private readonly array $photos = [],
        private readonly array $destaques = [],
    ) {
    }

    public function fetchAlbums(): array
    {
        return $this->albums;
    }

    public function fetchPhotosForAlbum(int $albumId): array
    {
        $rows = array_values(array_filter(
            $this->photos,
            static fn (array $p): bool => $p['id_album'] === $albumId
        ));
        usort($rows, static function (array $a, array $b): int {
            return [$a['ordem'], $a['id_foto']] <=> [$b['ordem'], $b['id_foto']];
        });

        return $rows;
    }

    public function fetchDestaques(): array
    {
        return $this->destaques;
    }
}
