<?php

namespace App\Services\Discord;

use App\Enums\AlliancePositionEnum;
use App\Enums\DiscordQueueStatus;
use App\Models\DiscordCityTierRole;
use App\Models\DiscordQueue;
use App\Models\User;
use App\Services\AllianceMembershipService;
use App\Services\SettingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DiscordCityTierSyncService
{
    public const ACTION = 'CITY_TIER_SYNC';

    public const CONTRACT_VERSION = 1;

    public function __construct(
        private readonly AllianceMembershipService $allianceMembership,
        private readonly DiscordQueueService $discordQueue,
    ) {}

    /**
     * Queue a complete, authoritative Discord city-tier snapshot.
     */
    public function queueSnapshot(): DiscordQueue
    {
        $this->importLatestCompletedResult();

        $bucketSize = SettingService::getDiscordCityTierBucketSize();
        $members = $this->eligibleMembers();
        $roles = $this->rolesThroughMaximumCityCount($members, $bucketSize);

        $payload = [
            'contract_version' => self::CONTRACT_VERSION,
            'bucket_size' => $bucketSize,
            'roles' => $roles->map(fn (DiscordCityTierRole $role): array => [
                'bucket_start' => $role->bucket_start,
                'bucket_end' => $role->bucket_end,
                'name' => $this->roleName($role->bucket_start, $role->bucket_end),
                'discord_role_id' => $role->discord_role_id,
            ])->values()->all(),
            'members' => $members->map(fn (array $member): array => [
                'discord_id' => $member['discord_id'],
                'bucket_start' => $this->bucketStart($member['num_cities'], $bucketSize),
            ])->values()->all(),
            'managed_role_ids' => DiscordCityTierRole::query()
                ->whereNotNull('discord_role_id')
                ->orderBy('bucket_start')
                ->pluck('discord_role_id')
                ->values()
                ->all(),
        ];

        return $this->discordQueue->enqueue(
            self::ACTION,
            $payload,
            dedupeKey: 'discord-city-tier-sync:'.now()->utc()->format('YmdH'),
        );
    }

    /**
     * Persist the role IDs returned by the most recently completed bot reconciliation.
     */
    public function importLatestCompletedResult(): int
    {
        $command = DiscordQueue::query()
            ->where('action', self::ACTION)
            ->where('status', DiscordQueueStatus::Complete->value)
            ->whereNotNull('result')
            ->latest('completed_at')
            ->first();

        if (! $command || ! is_array($command->result['roles'] ?? null)) {
            return 0;
        }

        $expectedBuckets = collect($command->payload['roles'] ?? [])
            ->filter(fn (mixed $role): bool => is_array($role))
            ->mapWithKeys(fn (array $role): array => [
                $this->bucketKey((int) ($role['bucket_start'] ?? 0), (int) ($role['bucket_end'] ?? 0)) => true,
            ]);

        $roles = collect($command->result['roles'])
            ->filter(function (mixed $role) use ($expectedBuckets): bool {
                if (! is_array($role)) {
                    return false;
                }

                $bucketStart = (int) ($role['bucket_start'] ?? 0);
                $bucketEnd = (int) ($role['bucket_end'] ?? 0);
                $discordRoleId = $role['discord_role_id'] ?? null;

                return $bucketStart > 0
                    && $bucketEnd >= $bucketStart
                    && is_string($discordRoleId)
                    && preg_match('/^\d{17,20}$/', $discordRoleId) === 1
                    && $expectedBuckets->has($this->bucketKey($bucketStart, $bucketEnd));
            })
            ->unique('discord_role_id')
            ->values();

        return DB::transaction(function () use ($roles, $command): int {
            $updated = 0;

            foreach ($roles as $role) {
                $updated += DiscordCityTierRole::query()
                    ->where('bucket_start', (int) $role['bucket_start'])
                    ->where('bucket_end', (int) $role['bucket_end'])
                    ->update([
                        'discord_role_id' => $role['discord_role_id'],
                        'last_synced_queue_id' => $command->id,
                    ]);
            }

            return $updated;
        });
    }

    /**
     * @return Collection<int, array{discord_id: string, num_cities: int}>
     */
    private function eligibleMembers(): Collection
    {
        $allianceIds = $this->allianceMembership->getAllianceIds()->all();

        if ($allianceIds === []) {
            return collect();
        }

        return User::query()
            ->where('disabled', false)
            ->whereNotNull('verified_at')
            ->whereHas('nation', function (Builder $query) use ($allianceIds): void {
                $query->whereIn('alliance_id', $allianceIds)
                    ->where('alliance_position', '!=', AlliancePositionEnum::APPLICANT->value)
                    ->where('num_cities', '>', 0);
            })
            ->whereHas('discordAccounts', fn (Builder $query): Builder => $query->whereNull('unlinked_at'))
            ->with([
                'nation:id,num_cities',
                'discordAccounts' => fn ($query) => $query
                    ->whereNull('unlinked_at')
                    ->latest('linked_at'),
            ])
            ->get()
            ->map(function (User $user): ?array {
                $discordAccount = $user->discordAccounts->first();
                $discordId = (string) ($discordAccount?->discord_id ?? '');

                if (! $discordAccount || ! $user->nation || preg_match('/^\d{17,20}$/', $discordId) !== 1) {
                    return null;
                }

                return [
                    'discord_id' => $discordId,
                    'num_cities' => (int) $user->nation->num_cities,
                    'linked_at' => $discordAccount->linked_at,
                ];
            })
            ->filter()
            ->sortByDesc('linked_at')
            ->unique('discord_id')
            ->map(fn (array $member): array => [
                'discord_id' => $member['discord_id'],
                'num_cities' => $member['num_cities'],
            ])
            ->sortBy('discord_id')
            ->values();
    }

    /**
     * @param  Collection<int, array{discord_id: string, num_cities: int}>  $members
     * @return Collection<int, DiscordCityTierRole>
     */
    private function rolesThroughMaximumCityCount(Collection $members, int $bucketSize): Collection
    {
        $maximumCityCount = (int) $members->max('num_cities');

        if ($maximumCityCount < 1) {
            return collect();
        }

        return collect(range(1, (int) ceil($maximumCityCount / $bucketSize)))
            ->map(function (int $bucketNumber) use ($bucketSize): DiscordCityTierRole {
                $bucketStart = (($bucketNumber - 1) * $bucketSize) + 1;
                $bucketEnd = $bucketNumber * $bucketSize;

                return DiscordCityTierRole::query()->firstOrCreate([
                    'bucket_start' => $bucketStart,
                    'bucket_end' => $bucketEnd,
                ]);
            });
    }

    private function bucketStart(int $cityCount, int $bucketSize): int
    {
        return ((int) floor(($cityCount - 1) / $bucketSize) * $bucketSize) + 1;
    }

    private function roleName(int $bucketStart, int $bucketEnd): string
    {
        return "Cities {$bucketStart}-{$bucketEnd}";
    }

    private function bucketKey(int $bucketStart, int $bucketEnd): string
    {
        return $bucketStart.'-'.$bucketEnd;
    }
}
