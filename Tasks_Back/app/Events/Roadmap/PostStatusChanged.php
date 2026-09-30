<?php

namespace App\Events\Roadmap;

use App\Models\Roadmap\Post;
use Illuminate\Foundation\Events\Dispatchable;

/** Fired after a post's status changed. No listeners in v1 (hook for email / linked task sync). */
class PostStatusChanged
{
    use Dispatchable;

    public function __construct(public Post $post, public ?int $fromStatusId, public int $toStatusId) {}
}
