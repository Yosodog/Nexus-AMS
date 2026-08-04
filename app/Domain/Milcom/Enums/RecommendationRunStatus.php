<?php

namespace App\Domain\Milcom\Enums;

enum RecommendationRunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Superseded = 'superseded';
}
