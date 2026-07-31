<?php

namespace App\Enum;

enum AlbumPhotoLayout: string
{
    case Grid = 'grid';
    case MasonryVertical = 'masonry_vertical';
    case MasonryHorizontal = 'masonry_horizontal';
}
