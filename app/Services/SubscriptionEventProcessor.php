<?php

namespace App\Services;

use App\Events\WarDeclared;
use App\Exceptions\PWQueryFailedException;
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
use Illuminate\Http\Client\ConnectionException;
use InvalidArgumentException;

class SubscriptionEventProcessor
{
    /** @var array<string, list<string>> */
    private const SUPPORTED_EVENTS = [
        'nation' => ['create', 'update', 'delete'],
        'alliance' => ['create', 'update', 'delete'],
        'city' => ['create', 'update', 'delete'],
        'war' => ['create', 'update', 'delete'],
        'warattack' => ['create'],
        'account' => ['create', 'update', 'delete'],
    ];

    public function __construct(
        private readonly AllianceMembershipService $allianceMembershipService,
        private readonly NationProfitabilityService $nationProfitabilityService,
    ) {}

    /**
     * @param  array<int|string, mixed>  $payload
     *
     * @throws ConnectionException
     * @throws PWQueryFailedException
     */
    public function process(string $model, string $event, array $payload): void
    {
        $model = strtolower(trim($model));
        $event = strtolower(trim($event));

        if (! in_array($event, self::SUPPORTED_EVENTS[$model] ?? [], true)) {
            throw new InvalidArgumentException("Unsupported subscription event [{$model}:{$event}].");
        }

        $records = $this->normalizePayload($payload);

        if ($records === []) {
            return;
        }

        switch ("{$model}:{$event}") {
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
                $this->createAlliances($records);
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

    /**
     * @param  array<int|string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function normalizePayload(array $payload): array
    {
        if ($payload === []) {
            return [];
        }

        if (! array_is_list($payload)) {
            if (! array_key_exists('id', $payload)) {
                throw new InvalidArgumentException('A subscription object payload must contain an ID.');
            }

            $payload = [$payload];
        }

        foreach ($payload as $record) {
            if (! is_array($record) || ! array_key_exists('id', $record)) {
                throw new InvalidArgumentException('Each subscription record must be an object containing an ID.');
            }
        }

        /** @var list<array<string, mixed>> $payload */
        return $payload;
    }

    /** @param  list<array<string, mixed>>  $records */
    private function deleteNations(array $records): void
    {
        foreach ($records as $record) {
            Nation::query()->find($record['id'])?->delete();
            $this->nationProfitabilityService->deleteStoredSnapshotForNationId((int) $record['id']);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $records
     *
     * @throws ConnectionException
     * @throws PWQueryFailedException
     */
    private function createAlliances(array $records): void
    {
        foreach ($records as $record) {
            $alliance = AllianceQueryService::getAllianceById($record['id']);
            Alliance::updateFromAPI($alliance);
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

            $war = War::updateFromAPI((object) $record);

            if (! $war->wasRecentlyCreated) {
                continue;
            }

            event(new WarDeclared(
                warId: $record['id'],
                attackerNationId: $record['att_id'],
                attackerAllianceId: $record['att_alliance_id'] ?? null,
                attackerAlliancePosition: $record['att_alliance_position'] ?? null,
                defenderNationId: $record['def_id'],
                defenderAllianceId: $record['def_alliance_id'] ?? null,
                defenderAlliancePosition: $record['def_alliance_position'] ?? null,
            ));
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
