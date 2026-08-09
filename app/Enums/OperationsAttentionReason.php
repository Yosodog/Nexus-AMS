<?php

namespace App\Enums;

enum OperationsAttentionReason: string
{
    case Overdue = 'overdue';
    case DueSoon = 'due_soon';
    case Blocked = 'blocked';
    case FailedDelivery = 'failed_delivery';
    case CriticalGap = 'critical_gap';
    case UnassignedStaff = 'unassigned_staff';
    case RecentChange = 'recent_change';
    case StaleSource = 'stale_source';
    case Escalated = 'escalated';
    case Aged = 'aged';
}
