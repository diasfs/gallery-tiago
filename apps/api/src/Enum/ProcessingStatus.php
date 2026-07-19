<?php

namespace App\Enum;

enum ProcessingStatus: string
{
    case Pending = 'pending';
    case Converting = 'converting';
    case Detecting = 'detecting';
    case Done = 'done';
    case Failed = 'failed';
}
