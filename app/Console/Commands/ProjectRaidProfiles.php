<?php

namespace App\Console\Commands;

use App\Models\RaidTargetProfile;
use App\Services\Economy\MarketValuationService;
use App\Services\Raids\RaidResources;
use App\Services\RaidStockpileEstimator;
use App\Services\RuntimeCapabilities;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

#[Signature('raids:project-profiles')]
#[Description('Recompute the projected stockpile value of every raid target profile')]
final class ProjectRaidProfiles extends Command
{
    private const CHUNK = 1000;

    public function handle(
        RuntimeCapabilities $capabilities,
        RaidStockpileEstimator $estimator,
        MarketValuationService $valuation,
    ): int {
        if (! $capabilities->writesPublicWorld()) {
            $this->components->info('Raid profile projection is unavailable in this runtime.');

            return self::SUCCESS;
        }

        $prices = $valuation->current();
        $now = CarbonImmutable::now();
        $count = 0;

        RaidTargetProfile::query()
            ->whereNotNull('computed_at')
            ->chunkById(self::CHUNK, function (Collection $profiles) use ($estimator, $prices, $now, &$count): void {
                $values = $profiles->mapWithKeys(fn (RaidTargetProfile $profile): array => [
                    (int) $profile->nation_id => RaidResources::liquidationValue($estimator->project($profile, $now)['resources'], $prices),
                ]);

                $this->writeProjections($values, $now);
                $count += $values->count();
            }, 'nation_id');

        $this->components->info("Projected {$count} raid target profiles.");

        return self::SUCCESS;
    }

    /**
     * Write projected values for a chunk with one bulk UPDATE.
     *
     * @param  Collection<int, float>  $values  keyed by nation id
     */
    private function writeProjections(Collection $values, CarbonImmutable $now): void
    {
        $model = new RaidTargetProfile;
        $connection = $model->getConnection();
        $table = $connection->getQueryGrammar()->wrapTable($model->getTable());
        $cases = $values->map(fn (): string => 'WHEN ? THEN ?')->implode(' ');
        $placeholders = $values->map(fn (): string => '?')->implode(', ');
        $timestamp = $now->format($connection->getQueryGrammar()->getDateFormat());

        $connection->update(
            "UPDATE {$table} SET projected_value = CASE nation_id {$cases} END, projected_at = ?, updated_at = ? WHERE nation_id IN ({$placeholders})",
            [
                ...$values->flatMap(fn (float $value, int $nationId): array => [$nationId, $value])->all(),
                $timestamp,
                $timestamp,
                ...$values->keys()->all(),
            ],
        );
    }
}
