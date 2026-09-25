<?php

namespace App\Support\Raids;

use App\Services\Economy\EconomyRules;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Maps stored attack evidence onto `raid_loot_events` rows.
 *
 * Two input shapes are accepted:
 * - an attack observation row (`id, war_id, att_id, def_id, occurred_at, payload`), where
 *   `payload` is the JSON attack payload captured with its war context;
 * - a world `war_attacks` row (`id, war_id, att_id, def_id, date, type, victor, money_looted,
 *   {resource}_looted`) with an optional `war` key holding the war row
 *   (`def_id, war_type, att_alliance_id, def_alliance_id`).
 */
final class RaidLootObservationMapper
{
    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    public static function map(array $row): ?array
    {
        return array_key_exists('payload', $row)
            ? self::fromObservation($row)
            : self::fromWarAttack($row);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private static function fromObservation(array $row): ?array
    {
        $payload = $row['payload'];

        if (is_string($payload)) {
            try {
                $payload = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                return null;
            }
        }

        if (! is_array($payload)) {
            return null;
        }

        $kind = self::kind($payload['type'] ?? null);

        if ($kind === null) {
            return null;
        }

        $attackerId = (int) ($row['att_id'] ?? $payload['att_id'] ?? 0);
        $defenderId = (int) ($row['def_id'] ?? $payload['def_id'] ?? 0);
        $winnerId = (int) ($payload['victor'] ?? 0) > 0 ? (int) $payload['victor'] : $attackerId;
        $loserId = $winnerId === $attackerId ? $defenderId : $attackerId;
        $loserAllianceId = $loserId === (int) ($payload['original_defender_id'] ?? 0)
            ? ($payload['def_alliance_id'] ?? null)
            : ($payload['att_alliance_id'] ?? null);

        return self::row(
            id: (int) $row['id'],
            warId: (int) ($row['war_id'] ?? $payload['war_id'] ?? 0),
            kind: $kind,
            occurredAt: $row['occurred_at'] ?? $payload['date'] ?? null,
            winnerId: $winnerId,
            loserId: $loserId,
            loserAllianceId: $loserAllianceId,
            warType: $payload['war_type'] ?? null,
            source: $payload,
        );
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private static function fromWarAttack(array $row): ?array
    {
        $kind = self::kind($row['type'] ?? null);

        if ($kind === null) {
            return null;
        }

        $attackerId = (int) ($row['att_id'] ?? 0);
        $defenderId = (int) ($row['def_id'] ?? 0);
        $winnerId = (int) ($row['victor'] ?? 0) > 0 ? (int) $row['victor'] : $attackerId;
        $loserId = $winnerId === $attackerId ? $defenderId : $attackerId;
        $war = is_array($row['war'] ?? null) ? $row['war'] : null;
        $loserAllianceId = $war === null
            ? null
            : ($loserId === (int) ($war['def_id'] ?? 0) ? ($war['def_alliance_id'] ?? null) : ($war['att_alliance_id'] ?? null));

        return self::row(
            id: (int) $row['id'],
            warId: (int) ($row['war_id'] ?? 0),
            kind: $kind,
            occurredAt: $row['date'] ?? null,
            winnerId: $winnerId,
            loserId: $loserId,
            loserAllianceId: $loserAllianceId,
            warType: $war['war_type'] ?? null,
            source: $row,
        );
    }

    private static function kind(mixed $type): ?string
    {
        return match (strtoupper((string) $type)) {
            'VICTORY' => 'victory',
            'ALLIANCELOOT' => 'alliance_loot',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>|null
     */
    private static function row(
        int $id,
        int $warId,
        string $kind,
        mixed $occurredAt,
        int $winnerId,
        int $loserId,
        mixed $loserAllianceId,
        mixed $warType,
        array $source,
    ): ?array {
        $occurred = self::timestamp($occurredAt);

        if ($id <= 0 || $occurred === null || $winnerId <= 0 || $loserId <= 0) {
            return null;
        }

        $now = CarbonImmutable::now()->format('Y-m-d H:i:s');
        $row = [
            'id' => $id,
            'war_id' => $warId,
            'kind' => $kind,
            'occurred_at' => $occurred,
            'winner_nation_id' => $winnerId,
            'loser_nation_id' => $loserId,
            'loser_alliance_id' => (int) $loserAllianceId > 0 ? (int) $loserAllianceId : null,
            'war_type' => strtoupper((string) ($warType ?? 'ORDINARY')) ?: 'ORDINARY',
        ];

        foreach (EconomyRules::RESOURCE_KEYS as $resource) {
            $amount = $resource === 'money'
                ? ($source['money_looted'] ?? $source['money_stolen'] ?? 0)
                : ($source[$resource.'_looted'] ?? 0);
            $row[$resource] = round(max(0.0, is_numeric($amount) ? (float) $amount : 0.0), 2);
        }

        return $row + [
            'loot_fraction' => null,
            'fraction_source' => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private static function timestamp(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value)->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }
}
