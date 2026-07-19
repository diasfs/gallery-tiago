<?php

namespace App\Enum;

enum AlbumVisibility: string
{
    case Public = 'public';
    case Unlisted = 'unlisted';
    case Private = 'private';
}
