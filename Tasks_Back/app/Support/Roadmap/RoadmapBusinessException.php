<?php

namespace App\Support\Roadmap;

use RuntimeException;

/**
 * A predictable business-rule failure of the public API (400 + machine readable `code`),
 * e.g. submissions_closed, voting_closed, duplicate_content, too_fast, form_expired.
 * PublicApiGuard renders it; the message is written by us and safe to show.
 */
class RoadmapBusinessException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly int $status = 400)
    {
        parent::__construct($message);
    }
}
