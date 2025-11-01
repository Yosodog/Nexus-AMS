<?php

namespace Database\Seeders;

use App\Models\MMRSetting;
use App\Services\PWHelperService;
use Illuminate\Database\Seeder;

class MMRSettingSeeder extends Seeder
{
    public function run(): void
    {
        foreach (PWHelperService::resources(false) as $resource) {
            MMRSetting::firstOrCreate(
                ['resource' => $resource],
                [
                    'enabled' => true,
                    'surcharge_pct' => 5.00, // default +5%
                ]
            );
        }
    }
}
