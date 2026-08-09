<?php

namespace App\Enums;

enum OperationsNextActor: string
{
    case Staff = 'staff';
    case Requester = 'requester';
    case Participant = 'participant';
    case System = 'system';
}
