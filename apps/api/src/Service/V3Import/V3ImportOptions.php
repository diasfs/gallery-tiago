<?php

namespace App\Service\V3Import;

final class V3ImportOptions
{
    public function __construct(
        public readonly string $imgRoot,
        public readonly string $mapPath,
        public readonly bool $dryRun = false,
        public readonly ?int $limitAlbums = null,
        public readonly ?int $limitPhotos = null,
        public readonly ?string $albumUrl = null,
        public readonly bool $skipConvert = false,
    ) {
    }
}
