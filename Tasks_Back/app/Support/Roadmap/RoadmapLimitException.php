<?php

namespace App\Support\Roadmap;

use RuntimeException;

/** A durable (DB counted) cap was reached. PublicApiGuard renders 429 + Retry-After. */
class RoadmapLimitException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode = 'daily_limit',
        string $message = 'You have reached the daily limit. Please try again tomorrow.',
        public readonly int $retryAfter = 3600,
    ) {
        parent::__construct($message);
    }
}
