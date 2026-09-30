<?php

namespace App\Enums\Roadmap;

enum ChangelogLabel: string
{
    case New = 'new';
    case Improved = 'improved';
    case Fixed = 'fixed';
}
