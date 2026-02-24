<?php

namespace App\Services\War;

use App\Models\Nation;
use App\Models\War;
use App\Models\WarCounter;
use App\Models\WarPlan;
use App\Models\WarPlanAssignment;
use App\Services\AllianceMembershipService;
use App\Services\Discord\DiscordQueueService;
use App\Services\SettingService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Queues outbound notifications for war plan and counter events.
 *
 * Design Notes:
 * - In-game mail remains a placeholder and is intentionally not dispatched yet.
 * - Discord war rooms are queued for the Discord bot through discord_queue.
 * - War plans create one forum post per target, including all assigned participants.
 */
class NotificationService
{
    private const DISCORD_WAR_ROOM_ACTION = 'WAR_ROOM_CREATE';

    public function __construct(
        private readonly DiscordQueueService $discordQueueService,
        private readonly AllianceMembershipService $membershipService
    ) {}

    /**
     * Queue notifications for published plan assignments.
     *
     * @param  Collection<int, WarPlanAssignment>  $assignments
     * @param  array{in_game?:bool, create_room?:bool}  $channels
     * @return array{in_game_skipped:int, rooms_queued:int, skipped_no_forum:bool}
     */
    public function queuePlanPublishNotifications(
        WarPlan $plan,
        Collection $assignments,
        array $channels
    ): array {
        $result = [
            'in_game_skipped' => 0,
            'rooms_queued' => 0,
            'skipped_no_forum' => false,
        ];

        if ($channels['in_game'] ?? false) {
            $result['in_game_skipped'] = $assignments->count();
        }

        if (! ($channels['create_room'] ?? false)) {
            return $result;
        }

        if ($assignments instanceof EloquentCollection) {
            $assignments->loadMissing([
                'friendlyNation.alliance',
                'friendlyNation.military',
                'friendlyNation.user.discordAccounts',
                'friendlyNation.accountProfile',
                'target.nation.alliance',
                'target.nation.military',
            ]);
        }

        $forumChannelId = $this->resolveForumChannelId($plan->discord_forum_channel_id);

        if ($forumChannelId === '') {
            $result['skipped_no_forum'] = true;

            return $result;
        }

        $assignments
            ->groupBy('war_plan_target_id')
            ->each(function (Collection $targetAssignments) use ($plan, $forumChannelId, &$result): void {
                $firstAssignment = $targetAssignments->first();
                $targetNation = $firstAssignment?->target?->nation;

                if (! $targetNation) {
                    return;
                }

                $warType = (string) ($firstAssignment?->target?->preferred_war_type ?: $plan->plan_type);

                $this->discordQueueService->enqueue(self::DISCORD_WAR_ROOM_ACTION, [
                    'forum_channel_id' => $forumChannelId,
                    'source' => [
                        'type' => 'war_plan',
                        'id' => $plan->id,
                        'name' => $plan->name,
                        'url' => route('admin.war-plans.show', $plan),
                    ],
                    'target' => $this->buildTargetPayload($targetNation),
                    'attack_type' => [
                        'key' => $warType,
                        'label' => $this->warTypeLabel($warType),
                    ],
                    'assigned_members' => $this->buildAssignedMemberPayload($targetAssignments),
                    'links' => $this->buildTargetLinks($targetNation->id),
                    'room_name_suggestion' => sprintf(
                        'plan-%d-%s',
                        $plan->id,
                        Str::of($targetNation->leader_name ?: $targetNation->nation_name ?: 'target')->slug('-')
                    ),
                ]);

                $result['rooms_queued']++;
            });

        return $result;
    }

    /**
     * Queue notifications for counter finalization.
     *
     * @param  Collection<int, \App\Models\WarCounterAssignment>  $assignments
     * @param  array{in_game?:bool, create_room?:bool}  $channels
     * @return array{in_game_skipped:int, rooms_queued:int, skipped_no_forum:bool}
     */
    public function queueCounterFinalizedNotifications(
        WarCounter $counter,
        Collection $assignments,
        array $channels
    ): array {
        $result = [
            'in_game_skipped' => 0,
            'rooms_queued' => 0,
            'skipped_no_forum' => false,
        ];

        if ($channels['in_game'] ?? false) {
            $result['in_game_skipped'] = $assignments->count();
        }

        if (! ($channels['create_room'] ?? false)) {
            return $result;
        }

        $counter->loadMissing([
            'aggressor.alliance',
            'aggressor.military',
        ]);

        if ($assignments instanceof EloquentCollection) {
            $assignments->loadMissing([
                'friendlyNation.alliance',
                'friendlyNation.military',
                'friendlyNation.user.discordAccounts',
                'friendlyNation.accountProfile',
            ]);
        }

        $forumChannelId = $this->resolveForumChannelId($counter->discord_forum_channel_id);

        if ($forumChannelId === '') {
            $result['skipped_no_forum'] = true;

            return $result;
        }

        if (! $counter->aggressor) {
            return $result;
        }

        $warType = (string) ($counter->war_declaration_type ?: config('war.plan_defaults.plan_type', 'ordinary'));
        $counterReason = trim((string) ($counter->war_reason ?: $this->defaultCounterReason()));
        $attackedNation = $this->resolveCounterAttackedNation($counter);
        $assignedMembers = collect($this->buildAssignedMemberPayload($assignments));

        if ($attackedNation) {
            $attackedMemberPayload = $this->buildNationMemberPayload($attackedNation, null, 'defender');

            if ($attackedMemberPayload && ! $assignedMembers->contains(fn (array $member) => (int) ($member['nation_id'] ?? 0) === $attackedNation->id)) {
                $assignedMembers->push($attackedMemberPayload);
            }
        }

        $this->discordQueueService->enqueue(self::DISCORD_WAR_ROOM_ACTION, [
            'forum_channel_id' => $forumChannelId,
            'source' => [
                'type' => 'war_counter',
                'id' => $counter->id,
                'url' => route('admin.war-counters.show', $counter),
            ],
            'target' => $this->buildTargetPayload($counter->aggressor),
            'attack_type' => [
                'key' => $warType,
                'label' => $this->warTypeLabel($warType),
            ],
            'reason' => $counterReason,
            'attacked_member' => $attackedNation ? $this->buildNationMemberPayload($attackedNation, null, 'defender') : null,
            'assigned_members' => $assignedMembers->values()->all(),
            'links' => $this->buildTargetLinks($counter->aggressor->id),
            'room_name_suggestion' => sprintf(
                'counter-%d-%s-%d',
                $counter->id,
                Str::of($counter->aggressor->leader_name ?: $counter->aggressor->nation_name ?: 'target')->slug('-'),
                $counter->aggressor->id
            ),
        ]);

        $result['rooms_queued'] = 1;

        return $result;
    }

    protected function resolveForumChannelId(?string $overrideForumId): string
    {
        $overrideForumId = trim((string) $overrideForumId);

        if ($overrideForumId !== '') {
            return $overrideForumId;
        }

        return trim(SettingService::getDiscordWarRoomForumId());
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildTargetPayload(Nation $nation): array
    {
        $military = $nation->military;
        $alliance = $nation->alliance;

        return [
            'id' => $nation->id,
            'leader_name' => $nation->leader_name,
            'nation_name' => $nation->nation_name,
            'score' => $nation->score,
            'cities' => $nation->num_cities,
            'beige_turns' => $nation->beige_turns,
            'offensive_wars' => $nation->offensive_wars_count,
            'defensive_wars' => $nation->defensive_wars_count,
            'alliance' => $alliance ? [
                'id' => $alliance->id,
                'name' => $alliance->name,
                'acronym' => $alliance->acronym,
            ] : null,
            'military' => $military ? [
                'soldiers' => $military->soldiers,
                'tanks' => $military->tanks,
                'aircraft' => $military->aircraft,
                'ships' => $military->ships,
                'missiles' => $military->missiles,
                'nukes' => $military->nukes,
                'spies' => $military->spies,
            ] : null,
        ];
    }

    /**
     * @param  Collection<int, mixed>  $assignments
     * @return array<int, array<string, mixed>>
     */
    protected function buildAssignedMemberPayload(Collection $assignments): array
    {
        return $assignments
            ->map(function ($assignment): ?array {
                $friendly = $assignment->friendlyNation;

                if (! $friendly) {
                    return null;
                }

                return $this->buildNationMemberPayload($friendly, $assignment->match_score, 'counter');
            })
            ->filter()
            ->values()
            ->all();
    }

    protected function buildNationMemberPayload(Nation $nation, ?float $matchScore = null, string $role = 'counter'): ?array
    {
        $discordId = $this->resolveNationDiscordId($nation);

        return [
            'nation_id' => $nation->id,
            'leader_name' => $nation->leader_name,
            'nation_name' => $nation->nation_name,
            'role' => $role,
            'match_score' => $matchScore,
            'discord_id' => $discordId,
            'mention' => $discordId ? "<@{$discordId}>" : null,
            'score' => $nation->score,
            'cities' => $nation->num_cities,
            'offensive_wars' => $nation->offensive_wars_count,
            'defensive_wars' => $nation->defensive_wars_count,
            'links' => [
                'nation' => sprintf('https://politicsandwar.com/nation/id=%d', $nation->id),
            ],
        ];
    }

    protected function resolveNationDiscordId(Nation $nation): ?string
    {
        $user = $nation->user;

        $userDiscordId = null;

        if ($user) {
            if ($user->relationLoaded('discordAccounts')) {
                $activeDiscordAccount = $user->discordAccounts
                    ->whereNull('unlinked_at')
                    ->sortByDesc('linked_at')
                    ->first();

                $userDiscordId = $activeDiscordAccount?->discord_id;
            } else {
                $userDiscordId = $user->activeDiscordAccount()?->discord_id;
            }
        }

        if (is_string($userDiscordId) && $userDiscordId !== '') {
            return $userDiscordId;
        }

        $accountDiscordId = $nation->accountProfile?->discord_id;

        if (is_string($accountDiscordId) && $accountDiscordId !== '') {
            return $accountDiscordId;
        }

        $nationDiscordId = $nation->discord_id;

        if (is_string($nationDiscordId) && $nationDiscordId !== '') {
            return $nationDiscordId;
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    protected function buildTargetLinks(int $targetNationId): array
    {
        return [
            'declare_war' => sprintf('https://politicsandwar.com/nation/war/declare/id=%d', $targetNationId),
            'target_nation' => sprintf('https://politicsandwar.com/nation/id=%d', $targetNationId),
            'war_simulators' => route('defense.simulators'),
        ];
    }

    protected function warTypeLabel(string $warType): string
    {
        return config('war.war_types')[strtolower($warType)]
            ?? Str::of($warType)->replace('_', ' ')->title()->toString();
    }

    protected function defaultCounterReason(): string
    {
        $primaryAllianceId = $this->membershipService->getPrimaryAllianceId();
        $allianceName = \App\Models\Alliance::query()->whereKey($primaryAllianceId)->value('name');

        return sprintf('%s Counter', $allianceName ?: config('app.name', 'Alliance'));
    }

    protected function resolveCounterAttackedNation(WarCounter $counter): ?Nation
    {
        $friendlyAllianceIds = $this->membershipService->getAllianceIds()->filter()->values();

        if ($friendlyAllianceIds->isEmpty()) {
            return null;
        }

        $latestWar = War::query()
            ->where('att_id', $counter->aggressor_nation_id)
            ->whereIn('def_alliance_id', $friendlyAllianceIds->all())
            ->latest('date')
            ->with([
                'defender.user.discordAccounts',
                'defender.accountProfile',
            ])
            ->first();

        return $latestWar?->defender;
    }
}
