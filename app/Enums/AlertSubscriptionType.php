<?php

namespace App\Enums;

enum AlertSubscriptionType: string
{
    case Nation = 'nation';
    case Alliance = 'alliance';
    case Market = 'market';

    public function label(): string
    {
        return match ($this) {
            self::Nation => 'Nation watch',
            self::Alliance => 'Alliance watch',
            self::Market => 'Market price alert',
        };
    }

    /** @return array<string, string> */
    public function events(): array
    {
        return match ($this) {
            self::Nation => [
                'alliance_changed' => 'Alliance changed',
                'vacation_mode_entered' => 'Entered vacation mode',
                'vacation_mode_exited' => 'Exited vacation mode',
                'beige_exited' => 'Exited beige',
                'city_count_changed' => 'City count changed',
                'war_state_changed' => 'Active war count changed',
            ],
            self::Alliance => [
                'membership_changed' => 'Membership changed',
                'treaty_changed' => 'Treaty added, removed, or changed',
            ],
            self::Market => [],
        };
    }

    /** @return array<string, string> */
    public static function resources(): array
    {
        return [
            'coal' => 'Coal',
            'oil' => 'Oil',
            'uranium' => 'Uranium',
            'iron' => 'Iron',
            'bauxite' => 'Bauxite',
            'lead' => 'Lead',
            'gasoline' => 'Gasoline',
            'munitions' => 'Munitions',
            'steel' => 'Steel',
            'aluminum' => 'Aluminum',
            'food' => 'Food',
            'credits' => 'Credits',
        ];
    }
}
