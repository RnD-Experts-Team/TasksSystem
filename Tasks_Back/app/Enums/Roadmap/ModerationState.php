<?php

namespace App\Enums\Roadmap;

enum ModerationState: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Spam = 'spam';
}
