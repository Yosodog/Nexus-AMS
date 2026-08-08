<?php

namespace App\Domain\Federation\Enums;

enum ReceivedResourceState: string
{
    case PendingReview = 'pending_review';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Revoked = 'revoked';
    case Expired = 'expired';
}
