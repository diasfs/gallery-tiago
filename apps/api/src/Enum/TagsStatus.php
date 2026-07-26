<?php

namespace App\Enum;

enum TagsStatus: string
{
    case Pending = 'pending';
    case Detecting = 'detecting';
    case Done = 'done';
    case Failed = 'failed';
}
