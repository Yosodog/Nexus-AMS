<?php

namespace App\Console\Commands;

use App\Models\RaidLootEvent;
use App\Models\WarAttack;
use App\Services\GraphQLQueryBuilder;
use App\Services\QueryService;
use App\Services\Raids\RaidLootEventRecorder;
use App\Services\RuntimeCapabilities;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One-off import of recent world victory and alliance-loot attacks.
 *
 * Pages the `warattacks` root by ascending `min_id`. Confirm the root's argument names
 * against the live API schema before running it against production.
 */
#[Signature('raids:backfill-loot-events {--days=90 : Import attacks from the last N days} {--min-id= : Start from this attack id instead of the local evidence}')]
#[Description('Import recent world victory and alliance-loot attacks as raid loot events')]
final class BackfillRaidLootEvents extends Command
{
    private const PAGE_SIZE = 500;

    private const FIELDS = [
        'id', 'date', 'att_id', 'def_id', 'type', 'war_id', 'victor', 'loot_info',
        'money_looted', 'money_stolen', 'coal_looted', 'oil_looted', 'uranium_looted', 'iron_looted',
        'bauxite_looted', 'lead_looted', 'gasoline_looted', 'munitions_looted', 'steel_looted',
        'aluminum_looted', 'food_looted',
    ];

    public function handle(RuntimeCapabilities $capabilities, QueryService $queries, RaidLootEventRecorder $recorder): int
    {
        if (! $capabilities->writesPublicWorld()) {
            $this->components->info('Raid loot backfill is unavailable in this runtime.');

            return self::SUCCESS;
        }

        $days = (int) $this->option('days');

        if ($days < 1) {
            $this->components->error('The --days option must be a positive integer.');

            return self::INVALID;
        }

        $cursor = $this->startingId($days);

        if ($cursor === null) {
            $this->components->error('No local attack evidence is inside the window; pass --min-id to choose a starting attack id.');

            return self::FAILURE;
        }

        $recorded = 0;

        while (true) {
            $attacks = $this->page($queries, $cursor);

            foreach ($attacks as $attack) {
                try {
                    if ($recorder->record($attack) !== null) {
                        $recorded++;
                    }
                } catch (Throwable $exception) {
                    Log::warning('Raid loot event could not be recorded.', [
                        'attack_id' => $attack['id'] ?? null,
                        'exception_class' => $exception::class,
                    ]);
                }
            }

            if (count($attacks) < self::PAGE_SIZE) {
                break;
            }

            $cursor = max(array_map(fn (array $attack): int => (int) ($attack['id'] ?? 0), $attacks)) + 1;
        }

        $this->components->info("Recorded {$recorded} raid loot events.");

        return self::SUCCESS;
    }

    private function startingId(int $days): ?int
    {
        if ($this->option('min-id') !== null) {
            return max(1, (int) $this->option('min-id'));
        }

        $cutoff = now()->subDays($days);
        $candidates = array_filter([
            WarAttack::query()->where('date', '>=', $cutoff)->min('id'),
            RaidLootEvent::query()->where('occurred_at', '>=', $cutoff)->min('id'),
        ], fn (mixed $id): bool => $id !== null);

        return $candidates === [] ? null : (int) min($candidates);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function page(QueryService $queries, int $cursor): array
    {
        $query = (new GraphQLQueryBuilder)
            ->setRootField('warattacks')
            ->addArgument([
                'min_id' => $cursor,
                'first' => self::PAGE_SIZE,
                'orderBy' => [['column' => GraphQLQueryBuilder::literal('ID'), 'order' => GraphQLQueryBuilder::literal('ASC')]],
            ])
            ->addNestedField('data', fn (GraphQLQueryBuilder $builder) => $builder->addFields(self::FIELDS));
        $rows = json_decode(json_encode($queries->sendQuery($query, handlePagination: false), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

        return array_values(array_filter((array) $rows, 'is_array'));
    }
}
