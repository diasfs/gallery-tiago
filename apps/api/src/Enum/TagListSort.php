<?php

namespace App\Enum;

enum TagListSort: string
{
    case Name = 'name';
    case Slug = 'slug';
    case Recent = 'recent';
}
