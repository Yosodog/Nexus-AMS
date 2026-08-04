<?php

namespace App\Domain\Milcom\Enums;

enum OperationStatus: string
{
    case Draft = 'draft';
    case Generating = 'generating';
    case Review = 'review';
    case Dispatching = 'dispatching';
    case Active = 'active';
    case Completed = 'completed';
    case Archived = 'archived';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Archived], true);
    }
}
