<?php

namespace Database\Seeders;

use App\Models\PayrollMember;
use Illuminate\Database\Seeder;

class PayrollMemberSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        PayrollMember::factory()->count(5)->create();
    }
}
