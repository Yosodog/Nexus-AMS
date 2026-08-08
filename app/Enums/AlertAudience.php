<?php

namespace App\Enums;

enum AlertAudience: string
{
    case Member = 'member';
    case Staff = 'staff';
    case Administrator = 'administrator';
}
