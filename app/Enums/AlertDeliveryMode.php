<?php

namespace App\Enums;

enum AlertDeliveryMode: string
{
    case Immediate = 'immediate';
    case Daily = 'daily';
    case Weekly = 'weekly';
}
