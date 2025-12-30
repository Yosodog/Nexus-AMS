<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $average = DB::table('settings')
            ->where('key', 'pw_city_average')
            ->value('value');

        if ($average === null) {
            $refreshed = app(\App\Services\CityCostService::class)->refreshTop20Average();
            $average = $refreshed ?? DB::table('settings')
                ->where('key', 'pw_city_average')
                ->value('value');
        }

        if ($average === null) {
            throw new \RuntimeException('Missing pw_city_average setting. Run `php artisan pw:sync-city-average` before migrating.');
        }

        $top20Average = (float) $average;

        $grants = DB::table('city_grants')
            ->where('grant_amount', '>', 300)
            ->get();

        foreach ($grants as $grant) {
            if (! $grant->city_number) {
                continue;
            }

            $requirements = $grant->requirements ? json_decode($grant->requirements, true) : [];
            $projects = $requirements['required_projects'] ?? [];
            $requiresBda = in_array('Bureau of Domestic Affairs', $projects, true);
            $requiresGsa = in_array('Government Support Agency', $projects, true);

            $cityCost = $this->calculateCityCost(
                (int) $grant->city_number,
                $top20Average,
                $requiresBda,
                $requiresGsa
            );

            if ($cityCost <= 0.0) {
                continue;
            }

            $percentage = max(1, (int) round(($grant->grant_amount / $cityCost) * 100));

            DB::table('city_grants')
                ->where('id', $grant->id)
                ->update(['grant_amount' => $percentage]);
        }
    }

    public function down(): void {}

    private function calculateCityCost(
        int $cityNumber,
        float $top20Average,
        bool $requiresBda,
        bool $requiresGsa
    ): float {
        $adjusted = $cityNumber - ($top20Average / 4.0);
        $poly = (100000.0 * ($adjusted ** 3)) + (150000.0 * $adjusted) + 75000.0;
        $quad = ($cityNumber ** 2) * 100000.0;
        $baseCost = max($poly, $quad);

        $discount = 0.05;
        if ($requiresBda) {
            $discount += 0.0125;
        }
        if ($requiresGsa) {
            $discount += 0.025;
        }
        $final = $baseCost * (1.0 - $discount);

        return max(0.0, $final);
    }
};
