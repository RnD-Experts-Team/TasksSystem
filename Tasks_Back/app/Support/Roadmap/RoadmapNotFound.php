<?php

namespace App\Support\Roadmap;

use RuntimeException;

/**
 * Thrown by public services when a board/post/entry does not exist OR is not publicly visible.
 * PublicApiGuard turns it into the 404 envelope; the message is always generic.
 */
class RoadmapNotFound extends RuntimeException
{
    public function __construct(string $message = 'Not found')
    {
        parent::__construct($message);
    }
}
