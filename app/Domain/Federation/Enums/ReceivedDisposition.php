<?php

namespace App\Domain\Federation\Enums;

enum ReceivedDisposition: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
}
