<?php

namespace Database\Seeders;

use App\Services\AllianceSetup\AllianceSetupStateStore;
use Illuminate\Database\Seeder;

class AllianceSetupSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        app(AllianceSetupStateStore::class)->initializeFresh();
    }
}
