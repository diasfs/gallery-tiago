<?php

namespace App\Enum;

enum FacesStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Detecting = 'detecting';
    case Done = 'done';
    case Failed = 'failed';
    case Disabled = 'disabled';
}
