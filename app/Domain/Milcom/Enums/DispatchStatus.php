<?php

namespace App\Domain\Milcom\Enums;

enum DispatchStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Sent = 'sent';
    case Archived = 'archived';
    case Failed = 'failed';
}
