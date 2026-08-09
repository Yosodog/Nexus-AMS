<?php

namespace App\Enums;

enum OperationsFreshnessState: string
{
    case Fresh = 'fresh';
    case Aging = 'aging';
    case Stale = 'stale';
    case Unknown = 'unknown';
}
