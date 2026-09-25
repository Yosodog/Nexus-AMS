<?php

namespace App\Console\Commands;

use App\Models\Nation;
use App\Models\RaidLootEvent;
use App\Models\RaidTargetProfile;
use App\Services\Raids\RaidAllianceProfileBuilder;
use App\Services\Raids\RaidLootFraction;
use App\Services\Raids\RaidProfileDirtyMarker;
use App\Services\Raids\RaidTargetProfileBuilder;
use App\Services\RuntimeCapabilities;
use App\Services\WarSimulator\Support\RaidLootFormula;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

#[Signature('raids:rebuild {--resolve-fractions : Resolve loot fractions for events migrated without one}')]
#[Description('Rebuild every raid target profile and alliance counter rate')]
final class RebuildRaidProfiles extends Command
{
    private const CHUNK = 200;

    public function handle(
        RuntimeCapabilities $capabilities,
        RaidLootFraction $fractions,
        RaidProfileDirtyMarker $dirty,
        RaidTargetProfileBuilder $builder,
        RaidAllianceProfileBuilder $alliances,
    ): int {
        if (! $capabilities->writesPublicWorld()) {
            $this->components->info('Raid profile rebuild is unavailable in this runtime.');

            return self::SUCCESS;
        }

        if ($this->option('resolve-fractions')) {
            $this->components->info('Resolved '.$this->resolveFractions($fractions).' pending loot fractions.');
        }

        Nation::query()->select('id')->chunkById(1000, function (Collection $nations) use ($dirty): void {
            $dirty->mark($nations->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());
        });

        $query = RaidTargetProfile::query()->whereNotNull('dirty_at');
        $bar = $this->output->createProgressBar((clone $query)->count());
        $written = 0;

        $query->select('nation_id')->chunkById(self::CHUNK, function (Collection $profiles) use ($builder, $bar, &$written): void {
            $written += $builder->build($profiles->pluck('nation_id')->map(fn (mixed $id): int => (int) $id)->all());
            $bar->advance($profiles->count());
        }, 'nation_id');

        $bar->finish();
        $this->newLine();
        $this->components->info("Rebuilt {$written} raid target profiles.");
        $this->components->info('Refreshed '.$alliances->refreshCounterRates().' raid alliance profiles.');

        return self::SUCCESS;
    }

    private function resolveFractions(RaidLootFraction $fractions): int
    {
        $resolved = 0;

        RaidLootEvent::query()
            ->where('fraction_source', 'pending')
            ->chunkById(1000, function (Collection $events) use ($fractions, &$resolved): void {
                $nations = Nation::query()
                    ->whereIn('id', $events->pluck('winner_nation_id')->merge($events->pluck('loser_nation_id'))->unique()->all())
                    ->get()
                    ->keyBy('id');

                foreach ($events as $event) {
                    [$fraction, $source] = $this->fraction($fractions, $event, $nations);
                    $event->forceFill(['loot_fraction' => $fraction, 'fraction_source' => $source])->save();
                    $resolved++;
                }
            });

        return $resolved;
    }

    /**
     * @param  Collection<int, Nation>  $nations
     * @return array{0: float|null, 1: string}
     */
    private function fraction(RaidLootFraction $fractions, RaidLootEvent $event, Collection $nations): array
    {
        if ($event->kind !== RaidLootEvent::KIND_VICTORY) {
            return [null, 'default'];
        }

        $winner = $nations->get($event->winner_nation_id);
        $loser = $nations->get($event->loser_nation_id);

        if ($winner === null || $loser === null) {
            return [RaidLootFormula::DEFAULT_VICTORY_LOOT_FRACTION, 'default'];
        }

        $projects = $winner->projects;

        return [
            $fractions->fromModifiers(
                (string) $event->war_type,
                $winner->war_policy,
                $loser->war_policy,
                (bool) ($projects['pirate_economy'] ?? false),
                (bool) ($projects['advanced_pirate_economy'] ?? false),
            ),
            'modifiers',
        ];
    }
}
