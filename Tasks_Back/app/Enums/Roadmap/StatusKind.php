<?php

namespace App\Enums\Roadmap;

enum StatusKind: string
{
    case Open = 'open';
    case Planned = 'planned';
    case InProgress = 'in_progress';
    case Done = 'done';
    case Closed = 'closed';
}
