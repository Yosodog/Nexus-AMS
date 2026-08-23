<?php

namespace App\Enums;

enum AllianceSetupStep: string
{
    case Platform = 'platform';
    case Security = 'security';
    case Discord = 'discord';
    case Recruitment = 'recruitment';
    case Review = 'review';

    public function label(): string
    {
        return match ($this) {
            self::Platform => 'Platform & data',
            self::Security => 'Administrator security',
            self::Discord => 'Discord',
            self::Recruitment => 'Recruitment',
            self::Review => 'Review & finish',
        };
    }

    public function routeName(): string
    {
        return 'admin.setup.'.$this->value;
    }

    public function next(): ?self
    {
        return match ($this) {
            self::Platform => self::Security,
            self::Security => self::Discord,
            self::Discord => self::Recruitment,
            self::Recruitment => self::Review,
            self::Review => null,
        };
    }
}
