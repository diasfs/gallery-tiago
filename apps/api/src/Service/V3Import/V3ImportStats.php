<?php

namespace App\Service\V3Import;

final class V3ImportStats
{
    public int $albumsCreated = 0;
    public int $albumsUpdated = 0;
    public int $albumsSkipped = 0;
    public int $photosCreated = 0;
    public int $photosSkipped = 0;
    public int $photosMissingFile = 0;
    public int $convertDispatched = 0;
    public int $coversSet = 0;

    /** @var list<string> */
    public array $missingFiles = [];
}
