<?php

namespace App\Enum;

enum FacesStatus: string
{
    case Pending = 'pending';
    case Detecting = 'detecting';
    case Done = 'done';
    case Failed = 'failed';
}
