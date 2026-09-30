<?php

namespace App\Enums\Roadmap;

/** verified_email is reserved for a future release; only `anonymous` is active. */
enum VotingMode: string
{
    case Anonymous = 'anonymous';
    case VerifiedEmail = 'verified_email';
}
