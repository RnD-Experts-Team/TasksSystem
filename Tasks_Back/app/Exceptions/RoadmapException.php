<?php

namespace App\Exceptions;

/**
 * Business-rule violation inside the Roadmap admin module. Controllers map it to a 400
 * envelope carrying the machine-readable `code` (e.g. "board_not_empty").
 */
class RoadmapException extends \DomainException
{
    public function __construct(string $message, private string $errorCode = 'business_rule', int $status = 400)
    {
        parent::__construct($message, $status);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
