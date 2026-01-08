<?php

namespace Database\Seeders;

use App\Models\PayrollGrade;
use Illuminate\Database\Seeder;

class PayrollGradeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        PayrollGrade::factory()->count(3)->create();
    }
}
