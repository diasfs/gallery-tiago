<?php

namespace App\Enum;

enum MediaStatus: string
{
    case Pending = 'pending';
    case Converting = 'converting';
    case Done = 'done';
    case Failed = 'failed';
}
