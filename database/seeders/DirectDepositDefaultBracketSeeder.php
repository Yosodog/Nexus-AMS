<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DirectDepositDefaultBracketSeeder extends Seeder
{
    public function run(): void
    {
        $exists = DB::table('direct_deposit_tax_brackets')->where('city_number', 0)->exists();

        if (! $exists) {
            DB::table('direct_deposit_tax_brackets')->insert([
                'city_number' => 0,
                'money' => 10,
                'coal' => 10,
                'oil' => 10,
                'uranium' => 10,
                'iron' => 10,
                'bauxite' => 10,
                'lead' => 10,
                'gasoline' => 10,
                'munitions' => 10,
                'steel' => 10,
                'aluminum' => 10,
                'food' => 10,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
