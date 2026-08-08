<?php

namespace App\Domain\Federation\Enums;

enum InboxStatus: string
{
    case Accepted = 'accepted';
    case Processing = 'processing';
    case Processed = 'processed';
    case Quarantined = 'quarantined';
}
