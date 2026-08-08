<?php

namespace App\Domain\Federation\Enums;

enum CoalitionRole: string
{
    case Coordinator = 'coordinator';
    case Admin = 'admin';
    case Member = 'member';
    case Observer = 'observer';
}
