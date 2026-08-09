<?php

namespace App\Services\Discord;

use App\Models\Alliance;
use App\Models\DiscordAccount;
use App\Models\Nation;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

final class DiscordDirectoryService
{
    /** @return array<string, mixed> */
    public function discordUser(User $actor, DiscordAccount $actorAccount, string $discordUserId): array
    {
        $self = hash_equals((string) $actorAccount->discord_id, $discordUserId);
        if (! $self && (! $actor->is_admin || ! $actor->can('view-members'))) {
            throw new AuthorizationException('You do not have permission to view another member.');
        }

        if ($self) {
            $actor->loadMissing('nation.alliance');

            return $this->identityPayload($discordUserId, $actorAccount, $actor, true);
        }

        $accounts = DiscordAccount::query()
            ->with('user.nation.alliance')
            ->where('discord_id', $discordUserId)
            ->whereNull('unlinked_at')
            ->limit(2)
            ->get()
            ->values();

        if ($accounts->isEmpty()) {
            return [
                'contract_version' => 1,
                'state' => 'unlinked',
                'discord_user_id' => $discordUserId,
                'message' => 'This Discord user is not linked to an active Nexus account.',
            ];
        }

        if ($accounts->count() !== 1) {
            return [
                'contract_version' => 1,
                'state' => 'ambiguous',
                'discord_user_id' => $discordUserId,
                'message' => 'Nexus found multiple active links for this Discord user. An administrator must resolve them.',
            ];
        }

        $account = $accounts->first();
        $user = $account->user;
        if (! $user || $user->disabled || ! $user->isVerified() || ! $user->nation) {
            return [
                'contract_version' => 1,
                'state' => 'unavailable',
                'discord_user_id' => $discordUserId,
                'message' => 'This Discord link does not have an available verified Nexus member profile.',
            ];
        }

        return $this->identityPayload($discordUserId, $account, $user, false);
    }

    /** @return list<array<string, mixed>> */
    public function searchNations(string $query): array
    {
        $query = trim($query);
        $escaped = addcslashes($query, '%_\\');

        return Nation::query()
            ->select(['id', 'nation_name', 'leader_name', 'alliance_id', 'updated_at'])
            ->with('alliance:id,name,acronym')
            ->where(function (Builder $nationQuery) use ($query, $escaped): void {
                if (ctype_digit($query)) {
                    $nationQuery->orWhere('id', (int) $query);
                }

                $nationQuery
                    ->orWhere('nation_name', 'like', "%{$escaped}%")
                    ->orWhere('leader_name', 'like', "%{$escaped}%");
            })
            ->orderByRaw('CASE WHEN nation_name = ? THEN 0 WHEN leader_name = ? THEN 1 ELSE 2 END', [$query, $query])
            ->orderBy('nation_name')
            ->limit(20)
            ->get()
            ->map(fn (Nation $nation): array => [
                'id' => (int) $nation->id,
                'name' => (string) $nation->nation_name,
                'description' => trim(sprintf(
                    '%s%s',
                    $nation->leader_name,
                    $nation->alliance ? ' · '.$nation->alliance->name : '',
                )),
                'source_updated_at' => $nation->updated_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    public function nation(int $nationId): array
    {
        $nation = Nation::query()->with('alliance:id,name,acronym')->findOrFail($nationId);

        return [
            'contract_version' => 1,
            'kind' => 'nation',
            'id' => (int) $nation->id,
            'name' => (string) $nation->nation_name,
            'leader_name' => (string) $nation->leader_name,
            'alliance' => $nation->alliance ? [
                'id' => (int) $nation->alliance->id,
                'name' => (string) $nation->alliance->name,
                'acronym' => (string) $nation->alliance->acronym,
            ] : null,
            'alliance_position' => (string) $nation->alliance_position,
            'cities' => (int) $nation->num_cities,
            'score' => (float) $nation->score,
            'color' => (string) $nation->color,
            'vacation_mode_turns' => (int) $nation->vacation_mode_turns,
            'game_url' => 'https://politicsandwar.com/nation/id='.(int) $nation->id,
            'shareable' => true,
            'freshness' => $this->freshness($nation->updated_at),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function searchAlliances(string $query): array
    {
        $query = trim($query);
        $escaped = addcslashes($query, '%_\\');

        return Alliance::query()
            ->select(['id', 'name', 'acronym', 'rank', 'updated_at'])
            ->where(function (Builder $allianceQuery) use ($query, $escaped): void {
                if (ctype_digit($query)) {
                    $allianceQuery->orWhere('id', (int) $query);
                }

                $allianceQuery
                    ->orWhere('name', 'like', "%{$escaped}%")
                    ->orWhere('acronym', 'like', "%{$escaped}%");
            })
            ->orderByRaw('CASE WHEN name = ? THEN 0 WHEN acronym = ? THEN 1 ELSE 2 END', [$query, $query])
            ->orderBy('rank')
            ->limit(20)
            ->get()
            ->map(fn (Alliance $alliance): array => [
                'id' => (int) $alliance->id,
                'name' => (string) $alliance->name,
                'description' => sprintf('%s · Rank #%d', $alliance->acronym, (int) $alliance->rank),
                'source_updated_at' => $alliance->updated_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    public function alliance(int $allianceId): array
    {
        $alliance = Alliance::query()->withCount('nations')->findOrFail($allianceId);

        return [
            'contract_version' => 1,
            'kind' => 'alliance',
            'id' => (int) $alliance->id,
            'name' => (string) $alliance->name,
            'acronym' => (string) $alliance->acronym,
            'rank' => (int) $alliance->rank,
            'score' => (float) $alliance->score,
            'average_score' => (float) $alliance->average_score,
            'color' => (string) $alliance->color,
            'accepting_members' => (bool) $alliance->accept_members,
            'nation_count' => (int) $alliance->nations_count,
            'game_url' => 'https://politicsandwar.com/alliance/id='.(int) $alliance->id,
            'shareable' => true,
            'freshness' => $this->freshness($alliance->updated_at),
        ];
    }

    /** @return array<string, mixed> */
    private function identityPayload(
        string $discordUserId,
        DiscordAccount $account,
        User $user,
        bool $self,
    ): array {
        $nation = $user->nation;
        $sourceUpdatedAt = collect([$account->updated_at, $user->updated_at, $nation?->updated_at])
            ->filter(fn (mixed $value): bool => $value instanceof CarbonInterface)
            ->sortDesc()
            ->first();

        return [
            'contract_version' => 1,
            'state' => 'ready',
            'discord_user_id' => $discordUserId,
            'display_name' => (string) $user->name,
            'discord_username' => $account->discord_username,
            'nation' => $nation ? [
                'id' => (int) $nation->id,
                'name' => (string) $nation->nation_name,
                'leader_name' => (string) $nation->leader_name,
                'game_url' => 'https://politicsandwar.com/nation/id='.(int) $nation->id,
            ] : null,
            'alliance' => $nation?->alliance ? [
                'id' => (int) $nation->alliance->id,
                'name' => (string) $nation->alliance->name,
                'acronym' => (string) $nation->alliance->acronym,
            ] : null,
            'deep_link_path' => $self
                ? route('user.dashboard', absolute: false)
                : route('admin.members.show', ['Nation' => $nation->id], false),
            'freshness' => $this->freshness($sourceUpdatedAt),
        ];
    }

    /** @return array{state: string, generated_at: string, source_updated_at: ?string} */
    private function freshness(?CarbonInterface $sourceUpdatedAt): array
    {
        $generatedAt = now();
        $staleAfterSeconds = max(60, (int) config('services.discord.summary_stale_after_seconds', 1800));

        return [
            'state' => $sourceUpdatedAt?->lt($generatedAt->copy()->subSeconds($staleAfterSeconds)) === true
                ? 'stale'
                : ($sourceUpdatedAt ? 'fresh' : 'unknown'),
            'generated_at' => $generatedAt->toIso8601String(),
            'source_updated_at' => $sourceUpdatedAt?->toIso8601String(),
        ];
    }
}
