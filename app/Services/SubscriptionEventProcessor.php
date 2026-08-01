<?php

namespace App\Services;

use App\Events\WarDeclared;
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
use App\Models\WarDeclarationReceipt;
use Illuminate\Support\Facades\Validator;
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

    /** @var list<string> */
    private const POSITIVE_INTEGER_FIELDS = [
        'id',
        'nation_id',
        'alliance_id',
        'alliance_position_id',
        'att_id',
        'def_id',
        'war_id',
        'city_id',
        'att_alliance_id',
        'def_alliance_id',
        'winner_id',
        'ground_control',
        'air_superiority',
        'naval_blockade',
    ];

    /** @var list<string> */
    private const NON_NEGATIVE_INTEGER_FIELDS = [
        'turns_left',
        'rank',
        'credits',
        'att_points',
        'def_points',
        'att_resistance',
        'def_resistance',
        'def_soldiers_lost',
        'att_soldiers_lost',
        'def_tanks_lost',
        'att_tanks_lost',
        'def_aircraft_lost',
        'att_aircraft_lost',
        'def_ships_lost',
        'att_ships_lost',
        'att_missiles_used',
        'def_missiles_used',
        'att_nukes_used',
        'def_nukes_used',
    ];

    /** @var list<string> */
    private const BOOLEAN_FIELDS = [
        'accept_members',
        'att_peace',
        'def_peace',
        'att_fortify',
        'def_fortify',
    ];

    public function __construct(
        private readonly AllianceMembershipService $allianceMembershipService,
        private readonly NationProfitabilityService $nationProfitabilityService,
        private readonly SubscriptionRecordQuarantine $quarantine,
    ) {}

    /** @param  array<int|string, mixed>  $payload */
    public function process(string $model, string $event, array $payload): void
    {
        $model = strtolower(trim($model));
        $event = strtolower(trim($event));

        if (! in_array($event, self::SUPPORTED_EVENTS[$model] ?? [], true)) {
            throw new InvalidArgumentException("Unsupported subscription event [{$model}:{$event}].");
        }

        $records = $this->normalizePayload($model, $event, $payload);

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

    /**
     * @param  array<int|string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function normalizePayload(string $model, string $event, array $payload): array
    {
        if ($payload === []) {
            return [];
        }

        if (! array_is_list($payload)) {
            $payload = [$payload];
        }

        $validRecords = [];
        $rules = $this->rulesFor($model, $event);

        foreach ($payload as $record) {
            if (! is_array($record)) {
                $this->quarantine->quarantine($model, $event, $record, 'record_must_be_an_object');

                continue;
            }

            $validator = Validator::make($record, $rules);

            if ($validator->fails()) {
                $this->quarantine->quarantine(
                    $model,
                    $event,
                    $record,
                    'validation_failed: '.implode(' ', $validator->errors()->all())
                );

                continue;
            }

            $validRecords[] = $this->normalizeRecordTypes($record);
        }

        if ($validRecords === []) {
            throw new InvalidArgumentException("Subscription payload contains no valid records for [{$model}:{$event}].");
        }

        return $validRecords;
    }

    /** @return array<string, list<string>> */
    private function rulesFor(string $model, string $event): array
    {
        $rules = [
            'id' => ['required', 'integer', 'min:1'],
        ];

        $eventRules = match ("{$model}:{$event}") {
            'nation:create', 'nation:update' => [
                'alliance_id' => ['nullable', 'integer', 'min:1'],
                'alliance_position_id' => ['nullable', 'integer', 'min:1'],
                'alliance_position' => ['nullable', 'string', 'max:50'],
            ],
            'alliance:update' => [
                'name' => ['nullable', 'string', 'max:255'],
                'acronym' => ['nullable', 'string', 'max:10'],
                'score' => ['nullable', 'numeric'],
                'color' => ['nullable', 'string', 'max:20'],
                'average_score' => ['nullable', 'numeric'],
                'accept_members' => ['nullable', 'boolean'],
                'flag' => ['nullable', 'string', 'max:255'],
                'forum_link' => ['nullable', 'string', 'max:255'],
                'discord_link' => ['nullable', 'string', 'max:255'],
                'wiki_link' => ['nullable', 'string', 'max:255'],
                'rank' => ['nullable', 'integer', 'min:0'],
            ],
            'city:create', 'city:update' => [
                'nation_id' => ['nullable', 'integer', 'min:1'],
                'nuke_date' => ['nullable', 'date'],
            ],
            'war:create' => [
                ...$this->warRules(),
                'att_id' => ['required', 'integer', 'min:1'],
                'def_id' => ['required', 'integer', 'min:1'],
            ],
            'war:update' => $this->warRules(),
            'warattack:create' => [
                'att_id' => ['required', 'integer', 'min:1'],
                'def_id' => ['required', 'integer', 'min:1'],
                'war_id' => ['required', 'integer', 'min:1'],
                'city_id' => ['nullable', 'integer', 'min:1'],
                'type' => ['required', 'string', 'max:50'],
            ],
            'account:create', 'account:update' => [
                'credits' => ['nullable', 'integer', 'min:0'],
                'last_active' => ['nullable', 'date'],
                'discord_id' => ['nullable', 'string', 'max:32'],
            ],
            default => [],
        };

        return array_merge($rules, $eventRules);
    }

    /** @return array<string, list<string>> */
    private function warRules(): array
    {
        return [
            'date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:255'],
            'war_type' => ['nullable', 'string', 'max:50'],
            'turns_left' => ['nullable', 'integer', 'min:0'],
            'att_id' => ['nullable', 'integer', 'min:1'],
            'def_id' => ['nullable', 'integer', 'min:1'],
            'att_alliance_id' => ['nullable', 'integer', 'min:1'],
            'def_alliance_id' => ['nullable', 'integer', 'min:1'],
            'winner_id' => ['nullable', 'integer', 'min:1'],
            'ground_control' => ['nullable', 'integer', 'min:1'],
            'air_superiority' => ['nullable', 'integer', 'min:1'],
            'naval_blockade' => ['nullable', 'integer', 'min:1'],
            'att_alliance_position' => ['nullable', 'string', 'max:50'],
            'def_alliance_position' => ['nullable', 'string', 'max:50'],
            'att_peace' => ['nullable', 'boolean'],
            'def_peace' => ['nullable', 'boolean'],
            'att_fortify' => ['nullable', 'boolean'],
            'def_fortify' => ['nullable', 'boolean'],
            'att_points' => ['nullable', 'integer', 'min:0'],
            'def_points' => ['nullable', 'integer', 'min:0'],
            'att_resistance' => ['nullable', 'integer', 'min:0'],
            'def_resistance' => ['nullable', 'integer', 'min:0'],
            'def_soldiers_lost' => ['nullable', 'integer', 'min:0'],
            'att_soldiers_lost' => ['nullable', 'integer', 'min:0'],
            'def_tanks_lost' => ['nullable', 'integer', 'min:0'],
            'att_tanks_lost' => ['nullable', 'integer', 'min:0'],
            'def_aircraft_lost' => ['nullable', 'integer', 'min:0'],
            'att_aircraft_lost' => ['nullable', 'integer', 'min:0'],
            'def_ships_lost' => ['nullable', 'integer', 'min:0'],
            'att_ships_lost' => ['nullable', 'integer', 'min:0'],
            'att_missiles_used' => ['nullable', 'integer', 'min:0'],
            'def_missiles_used' => ['nullable', 'integer', 'min:0'],
            'att_nukes_used' => ['nullable', 'integer', 'min:0'],
            'def_nukes_used' => ['nullable', 'integer', 'min:0'],
            'att_gas_used' => ['nullable', 'numeric', 'min:0'],
            'def_gas_used' => ['nullable', 'numeric', 'min:0'],
            'att_mun_used' => ['nullable', 'numeric', 'min:0'],
            'def_mun_used' => ['nullable', 'numeric', 'min:0'],
            'att_alum_used' => ['nullable', 'numeric', 'min:0'],
            'def_alum_used' => ['nullable', 'numeric', 'min:0'],
            'att_steel_used' => ['nullable', 'numeric', 'min:0'],
            'def_steel_used' => ['nullable', 'numeric', 'min:0'],
            'att_infra_destroyed' => ['nullable', 'numeric', 'min:0'],
            'def_infra_destroyed' => ['nullable', 'numeric', 'min:0'],
            'att_money_looted' => ['nullable', 'numeric', 'min:0'],
            'def_money_looted' => ['nullable', 'numeric', 'min:0'],
            'att_infra_destroyed_value' => ['nullable', 'numeric', 'min:0'],
            'def_infra_destroyed_value' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function normalizeRecordTypes(array $record): array
    {
        foreach (self::POSITIVE_INTEGER_FIELDS as $field) {
            if (array_key_exists($field, $record) && $record[$field] !== null) {
                $record[$field] = (int) $record[$field];
            }
        }

        foreach (self::NON_NEGATIVE_INTEGER_FIELDS as $field) {
            if (array_key_exists($field, $record) && $record[$field] !== null) {
                $record[$field] = (int) $record[$field];
            }
        }

        foreach (self::BOOLEAN_FIELDS as $field) {
            if (array_key_exists($field, $record) && $record[$field] !== null) {
                $record[$field] = (bool) $record[$field];
            }
        }

        return $record;
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

            $war = War::updateFromAPI((object) $record);

            $war->getConnection()->transaction(function () use ($record): void {
                $receipt = WarDeclarationReceipt::query()->firstOrCreate([
                    'war_id' => (int) $record['id'],
                ]);

                if (! $receipt->wasRecentlyCreated) {
                    return;
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
            });
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
