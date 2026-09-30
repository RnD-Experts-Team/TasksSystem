<?php

namespace App\Events\Roadmap;

use App\Models\Roadmap\ChangelogEntry;
use Illuminate\Foundation\Events\Dispatchable;

/** Fired when a changelog entry is published. No listeners in v1. */
class ChangelogPublished
{
    use Dispatchable;

    public function __construct(public ChangelogEntry $entry) {}
}
