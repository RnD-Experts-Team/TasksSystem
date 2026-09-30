<?php

namespace App\Events\Roadmap;

use App\Models\Roadmap\Post;
use Illuminate\Foundation\Events\Dispatchable;

/** Fired when a post becomes publicly visible (auto-approved or approved by a moderator). No listeners in v1. */
class PostApproved
{
    use Dispatchable;

    public function __construct(public Post $post) {}
}
