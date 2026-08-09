<?php

namespace App\Enums;

enum OperationsSensitivity: string
{
    case Standard = 'standard';
    case Restricted = 'restricted';
    case Diagnostic = 'diagnostic';
}
