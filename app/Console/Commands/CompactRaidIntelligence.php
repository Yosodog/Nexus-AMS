<?php

namespace App\Console\Commands;

use App\DataTransferObjects\MarketPriceSet;
use App\Models\RaidAttackObservation;
use App\Models\RaidNationObservation;
use App\Services\Economy\MarketValuationService;
use App\Services\RaidIntelligenceService;
use App\Services\RaidNationSnapshotCompactor;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

#[Signature('raids:compact-intelligence
    {--batch=100 : Nations processed per batch}
    {--pretend : Report the transformation without changing rows}')]
#[Description('Compact raid nation snapshots into current rows and historical state intervals')]
class CompactRaidIntelligence extends Command
{
    public function handle(
        RaidNationSnapshotCompactor $compactor,
        RaidIntelligenceService $intelligence,
        MarketValuationService $valuation,
    ): int {
        $batchSize = max(1, min(1000, (int) $this->option('batch')));
        $pretend = (bool) $this->option('pretend');
        $cursor = 0;
        $nations = 0;
        $deleted = 0;
        $intervals = 0;
        $retentionCutoff = CarbonImmutable::now()->subDays((int) config('raids.checkpoint_retention_days', 31));
        try {
            $prices = $valuation->current();
        } catch (Throwable) {
            $prices = null;
        }

        do {
            $nationIds = RaidNationObservation::query()
                ->where('nation_id', '>', $cursor)
                ->distinct()
                ->orderBy('nation_id')
                ->limit($batchSize)
                ->pluck('nation_id')
                ->map(static fn (mixed $id): int => (int) $id);

            if ($nationIds->isEmpty()) {
                break;
            }

            $cursor = (int) $nationIds->last();
            $evidenceNationIds = $this->evidenceNationIds($nationIds, $retentionCutoff);
            foreach ($nationIds as $nationId) {
                $result = $this->compactNation(
                    $nationId,
                    $evidenceNationIds->contains($nationId),
                    $compactor,
                    $intelligence,
                    $prices,
                    $pretend,
                );
                $nations++;
                $deleted += $result['deleted'];
                $intervals += $result['intervals'];
            }

            $this->line("Processed {$nations} nations...");
        } while ($nationIds->count() === $batchSize);

        $action = $pretend ? 'would retain' : 'retained';
        $this->components->info("{$action} {$intervals} compact intervals and removed {$deleted} redundant rows across {$nations} nations.");

        return self::SUCCESS;
    }

    /** @param Collection<int, int> $nationIds @return Collection<int, int> */
    private function evidenceNationIds(Collection $nationIds, CarbonImmutable $cutoff): Collection
    {
        $attackerIds = RaidAttackObservation::query()
            ->where('occurred_at', '>=', $cutoff)
            ->whereIn('att_id', $nationIds)
            ->pluck('att_id');
        $defenderIds = RaidAttackObservation::query()
            ->where('occurred_at', '>=', $cutoff)
            ->whereIn('def_id', $nationIds)
            ->pluck('def_id');

        return $attackerIds->merge($defenderIds)->map(static fn (mixed $id): int => (int) $id)->unique()->values();
    }

    /** @return array{deleted: int, intervals: int} */
    private function compactNation(
        int $nationId,
        bool $hasRecentEvidence,
        RaidNationSnapshotCompactor $compactor,
        RaidIntelligenceService $intelligence,
        ?MarketPriceSet $prices,
        bool $pretend,
    ): array {
        $rows = RaidNationObservation::query()
            ->where('nation_id', $nationId)
            ->orderBy('observed_at')
            ->orderBy('id')
            ->get();
        if ($rows->isEmpty()) {
            return ['deleted' => 0, 'intervals' => 0];
        }

        $prepared = $rows->map(function (RaidNationObservation $row) use ($compactor, $intelligence, $prices): array {
            $payload = $row->payload ?? [];
            if (! isset($payload['daily_output'], $payload['daily_expenses'], $payload['daily_net']) && $prices !== null) {
                $payload = $intelligence->withEconomy($payload, $prices, $row->observed_at);
            } elseif (! isset($payload['daily_output'], $payload['daily_expenses'], $payload['daily_net'])) {
                $payload['economy_unavailable'] = 'Market or economy context was not available during compaction.';
            }
            $payload = $compactor->compact($payload);

            return [
                'row' => $row,
                'payload' => $payload,
                'hash' => $compactor->hash($payload, $row->provenance_war_ids ?? []),
            ];
        });
        $hasActiveWar = $prepared->contains(fn (array $item): bool => $this->hasActiveWar($item['payload']));

        if (! $hasRecentEvidence && ! $hasActiveWar) {
            $prepared = collect([$prepared->last()]);
        } else {
            $prepared = $prepared->reduce(function (Collection $intervals, array $item): Collection {
                $last = $intervals->last();
                if ($last !== null && hash_equals($last['hash'], $item['hash'])) {
                    $intervals->pop();
                    $item['valid_from'] = $last['valid_from'];
                }
                $item['valid_from'] ??= $item['row']->observed_at;
                $intervals->push($item);

                return $intervals;
            }, collect());
        }

        $keepIds = $prepared->pluck('row.id')->map(static fn (mixed $id): int => (int) $id)->all();
        $deleted = $rows->count() - count($keepIds);
        if ($pretend) {
            return ['deleted' => $deleted, 'intervals' => count($keepIds)];
        }

        RaidNationObservation::query()->getConnection()->transaction(function () use ($nationId, $prepared, $keepIds): void {
            RaidNationObservation::query()->where('nation_id', $nationId)->update(['current_key' => null]);
            RaidNationObservation::query()->where('nation_id', $nationId)->whereNotIn('id', $keepIds)->delete();
            $lastIndex = $prepared->keys()->last();
            foreach ($prepared as $item) {
                $row = $item['row'];
                $row->forceFill([
                    'current_key' => null,
                    'state_hash' => $item['hash'],
                    'valid_from' => $item['valid_from'] ?? $row->observed_at,
                    'confirmed_through' => $row->observed_at,
                    'payload' => $item['payload'],
                ])->save();
            }
            RaidNationObservation::query()
                ->whereKey($prepared->get($lastIndex)['row']->getKey())
                ->update(['current_key' => 1]);
        });

        return ['deleted' => $deleted, 'intervals' => count($keepIds)];
    }

    /** @param array<string, mixed> $payload */
    private function hasActiveWar(array $payload): bool
    {
        return collect($payload['active_wars'] ?? [])->contains(static fn (mixed $war): bool => is_array($war)
            && empty($war['end_date'])
            && (int) ($war['turns_left'] ?? 1) > 0
            && (int) ($war['winner_id'] ?? 0) === 0);
    }
}
