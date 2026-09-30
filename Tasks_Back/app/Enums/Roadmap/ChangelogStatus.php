<?php

namespace App\Enums\Roadmap;

enum ChangelogStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
