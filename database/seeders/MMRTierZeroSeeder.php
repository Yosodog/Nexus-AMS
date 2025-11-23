<?php

namespace Database\Seeders;

use App\Models\MMRTier;
use Illuminate\Database\Seeder;

class MMRTierZeroSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        MMRTier::firstOrCreate(
            ['city_count' => 0],
            [
                'money' => 0,
                'steel' => 0,
                'aluminum' => 0,
                'munitions' => 0,
                'uranium' => 0,
                'food' => 0,
                'gasoline' => 0,
                'barracks' => 0,
                'factories' => 0,
                'hangars' => 0,
                'drydocks' => 0,
                'missiles' => 0,
                'nukes' => 0,
                'spies' => 0,
            ]
        );
    }
}
