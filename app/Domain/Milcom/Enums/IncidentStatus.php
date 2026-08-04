<?php

namespace App\Domain\Milcom\Enums;

enum IncidentStatus: string
{
    case New = 'new';
    case CoveredByPlan = 'covered_by_plan';
    case Countering = 'countering';
    case Resolved = 'resolved';
    case Ignored = 'ignored';
}
