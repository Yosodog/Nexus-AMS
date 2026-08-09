<?php

namespace App\Services;

use App\Jobs\CreateAllianceJob;
use App\Jobs\CreateNationJob;
use App\Jobs\CreateWarAttackJob;
use App\Jobs\DeleteNationAccountJob;
use App\Jobs\RefreshNationProfitabilitySnapshotJob;
use App\Jobs\UpdateAllianceJob;
use App\Jobs\UpdateCityJob;
use App\Jobs\UpdateNationJob;
use App\Jobs\UpdateWarJob;
use App\Jobs\UpsertNationAccountJob;
use App\Models\Alliance;
use App\Models\City;
use App\Models\Nation;
use App\Models\War;
use App\Services\TenantEvents\WarDeclarationReactionService;
use InvalidArgumentException;

class SubscriptionEventProcessor
{
    public function __construct(
        private readonly AllianceMembershipService $allianceMembershipService,
        private readonly NationProfitabilityService $nationProfitabilityService,
        private readonly SubscriptionEventValidator $eventValidator,
        private readonly WarDeclarationReactionService $warDeclarations,
    ) {}

    /** @param  array<int|string, mixed>  $payload */
    public function process(string $model, string $event, array $payload): void
    {
        $subscriptionEvent = $this->eventValidator->validateAndNormalize($model, $event, $payload);
        $records = $subscriptionEvent->records;

        if ($records === []) {
            return;
        }

        switch ($subscriptionEvent->key()) {
            case 'nation:create':
                CreateNationJob::dispatch($records);
                break;
            case 'nation:update':
                UpdateNationJob::dispatch($records);
                break;
            case 'nation:delete':
                $this->deleteNations($records);
                break;
            case 'alliance:create':
                $this->queueAllianceCreation($records);
                break;
            case 'alliance:update':
                UpdateAllianceJob::dispatch($records);
                break;
            case 'alliance:delete':
                $this->deleteAlliances($records);
                break;
            case 'city:create':
            case 'city:update':
                UpdateCityJob::dispatch($records);
                break;
            case 'city:delete':
                $this->deleteCities($records);
                break;
            case 'war:create':
                $this->createWars($records);
                break;
            case 'war:update':
                UpdateWarJob::dispatch($records);
                break;
            case 'war:delete':
                $this->deleteWars($records);
                break;
            case 'warattack:create':
                CreateWarAttackJob::dispatch($records);
                break;
            case 'account:create':
            case 'account:update':
                UpsertNationAccountJob::dispatch($records);
                break;
            case 'account:delete':
                DeleteNationAccountJob::dispatch($records);
                break;
        }
    }

    /** @param  list<array<string, mixed>>  $records */
    private function deleteNations(array $records): void
    {
        foreach ($records as $record) {
            Nation::query()->find($record['id'])?->delete();
            $this->nationProfitabilityService->deleteStoredSnapshotForNationId((int) $record['id']);
        }
    }

    /** @param  list<array<string, mixed>>  $records */
    private function queueAllianceCreation(array $records): void
    {
        $maximum = max((int) config('subscriptions.ingestion.alliance_create_max_records'), 1);

        if (count($records) > $maximum) {
            throw new InvalidArgumentException("Alliance create batches may not exceed {$maximum} records.");
        }

        foreach ($records as $record) {
            CreateAllianceJob::dispatch((int) $record['id']);
        }
    }

    /** @param  list<array<string, mixed>>  $records */
    private function deleteAlliances(array $records): void
    {
        foreach ($records as $record) {
            Alliance::query()->find($record['id'])?->delete();
        }
    }

    /** @param  list<array<string, mixed>>  $records */
    private function deleteCities(array $records): void
    {
        foreach ($records as $record) {
            $city = City::query()->find($record['id']);

            if (! $city) {
                continue;
            }

            $nationId = (int) $city->nation_id;
            $city->delete();

            $nation = $nationId > 0
                ? Nation::query()
                    ->select(['id', 'alliance_id', 'alliance_position', 'vacation_mode_turns'])
                    ->find($nationId)
                : null;

            if ($nation && $this->nationProfitabilityService->shouldStoreSnapshotForNation($nation)) {
                RefreshNationProfitabilitySnapshotJob::dispatch($nationId);
            }
        }
    }

    /** @param  list<array<string, mixed>>  $records */
    private function createWars(array $records): void
    {
        foreach ($records as $record) {
            if (! $this->allianceMembershipService->contains($record['att_alliance_id'] ?? null)
                && ! $this->allianceMembershipService->contains($record['def_alliance_id'] ?? null)) {
                continue;
            }

            $this->warDeclarations->react(War::updateFromAPI((object) $record));
        }
    }

    /** @param  list<array<string, mixed>>  $records */
    private function deleteWars(array $records): void
    {
        foreach ($records as $record) {
            War::query()->find($record['id'])?->delete();
        }
    }
}
