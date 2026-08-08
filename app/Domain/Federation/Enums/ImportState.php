<?php

namespace App\Domain\Federation\Enums;

enum ImportState: string
{
    case NotRequested = 'not_requested';
    case Queued = 'queued';
    case Importing = 'importing';
    case Imported = 'imported';
    case BlockedMissingTargets = 'blocked_missing_targets';
    case SourceStale = 'source_stale';
    case Failed = 'failed';
}
