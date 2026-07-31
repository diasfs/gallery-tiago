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
     *     data: ?string,
     *     visit?: int,
     *     regs?: int
     * }> $albums
     * @param list<array{
     *     id_foto: int,
     *     id_album: int,
     *     titulo: ?string,
     *     foto: string,
     *     ordem: int,
     *     visit?: int
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
        return array_map(static function (array $row): array {
            $regs = (int) ($row['regs'] ?? 0);

            return $row + [
                'visit' => (int) ($row['visit'] ?? 0),
                'regs' => $regs >= 1 ? $regs : 48,
                'data_cadastro' => $row['data_cadastro'] ?? null,
            ];
        }, $this->albums);
    }

    public function fetchPhotosForAlbum(int $albumId): array
    {
        // Preserve caller/fixture order — this is the classic display sequence.
        return array_values(array_map(
            static fn (array $p): array => $p + ['visit' => (int) ($p['visit'] ?? 0)],
            array_filter(
                $this->photos,
                static fn (array $p): bool => $p['id_album'] === $albumId
            ),
        ));
    }

    public function fetchDestaques(): array
    {
        return $this->destaques;
    }
}
