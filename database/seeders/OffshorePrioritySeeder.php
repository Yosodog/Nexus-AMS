<?php

namespace Database\Seeders;

use App\Models\Offshore;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;

class OffshorePrioritySeeder extends Seeder
{
    /**
     * Seed initial offshores with predictable priority ordering.
     *
     * Production operators should update the $offshores array or provide environment overrides
     * before running this seeder so credentials are populated correctly.
     */
    public function run(): void
    {
        $offshores = [
            [
                'name' => 'Primary Offshore Bank',
                'priority' => 1,
                'enabled' => true,
                'min_money' => 0,
                'min_resources' => [],
                'api_key' => env('OFFSHORE_PRIMARY_API_KEY', ''),
                'api_mutation_key' => env('OFFSHORE_PRIMARY_MUTATION_KEY', ''),
            ],
            [
                'name' => 'Secondary Offshore Bank',
                'priority' => 2,
                'enabled' => true,
                'min_money' => 0,
                'min_resources' => [],
                'api_key' => env('OFFSHORE_SECONDARY_API_KEY', ''),
                'api_mutation_key' => env('OFFSHORE_SECONDARY_MUTATION_KEY', ''),
            ],
        ];

        foreach ($offshores as $offshore) {
            $payload = Arr::only($offshore, [
                'name',
                'priority',
                'enabled',
                'min_money',
                'min_resources',
            ]);

            $credentials = [
                'api_key' => $offshore['api_key'],
                'api_mutation_key' => $offshore['api_mutation_key'],
            ];

            Offshore::updateOrCreate(
                ['name' => $offshore['name']],
                array_merge($payload, $credentials)
            );
        }

        Offshore::orderBy('priority')
            ->orderBy('id')
            ->get()
            ->values()
            ->each(function (Offshore $offshore, int $index) {
                $offshore->update(['priority' => $index + 1]);
            });
    }
}
