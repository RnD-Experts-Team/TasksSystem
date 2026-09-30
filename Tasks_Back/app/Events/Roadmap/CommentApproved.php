<?php

namespace App\Events\Roadmap;

use App\Models\Roadmap\Comment;
use Illuminate\Foundation\Events\Dispatchable;

/** Fired when a comment becomes publicly visible. No listeners in v1. */
class CommentApproved
{
    use Dispatchable;

    public function __construct(public Comment $comment) {}
}
