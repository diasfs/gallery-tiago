<?php

namespace App\Service\V3Import;

/**
 * Read-only view of a gallery v3 MySQL schema (album / foto / destaque).
 */
interface V3GallerySourceInterface
{
    /**
     * @return list<array{
     *     id_album: int,
     *     id_pai: int,
     *     titulo: string,
     *     descricao: ?string,
     *     url: string,
     *     ativo: string,
     *     ordem: int,
     *     data: ?string
     * }>
     */
    public function fetchAlbums(): array;

    /**
     * @return list<array{
     *     id_foto: int,
     *     id_album: int,
     *     titulo: ?string,
     *     foto: string,
     *     ordem: int
     * }>
     */
    public function fetchPhotosForAlbum(int $albumId): array;

    /**
     * @return list<array{id_album: int, foto: string, url: string}>
     */
    public function fetchDestaques(): array;
}
