<?php

namespace App\Services\Discord;

use App\Enums\AlliancePositionEnum;
use App\Enums\DiscordQueueLane;
use App\Enums\DiscordQueueStatus;
use App\Models\DiscordAccount;
use App\Models\DiscordCityTierRole;
use App\Models\DiscordQueue;
use App\Models\Nation;
use App\Models\User;
use App\Services\AllianceMembershipService;
use App\Services\AuditLogger;
use App\Services\Discord\Relay\CanonicalJson;
use App\Services\Settings\ApplicationSettings;
use Illuminate\Support\Str;

final readonly class DiscordMemberProfileSyncService
{
    public const ACTION = 'MEMBER_PROFILE_SYNC';

    public const INTENT_ACTION = 'member.profile-sync';

    public function __construct(
        private ApplicationSettings $applicationSettings,
        private AllianceMembershipService $allianceMembership,
        private DiscordQueueService $queue,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  list<string>  $observedRoleIds
     * @return array<string, mixed>
     */
    public function preview(
        User $actor,
        DiscordAccount $discordAccount,
        DiscordConnectionContext $connection,
        ?string $observedNickname,
        array $observedRoleIds,
    ): array {
        $this->assertCapability($connection);
        $plan = $this->plan($actor, $discordAccount, $connection, $observedNickname, $observedRoleIds);

        return [
            'resource_version' => $plan['resource_version'],
            'plan_hash' => $plan['plan_hash'],
            'observed' => $plan['observed'],
            'summary' => [
                'title' => 'Synchronize your Nexus Discord profile?',
                'description' => $plan['change_count'] === 0
                    ? 'Your observed nickname and Nexus-managed roles already match Nexus policy.'
                    : 'Confirm to apply the nickname and managed-role changes calculated by Nexus.',
                'nickname' => [
                    'current' => $plan['observed']['nickname'],
                    'desired' => data_get($plan, 'queue_payload.desired.nickname'),
                    'will_change' => $plan['observed']['nickname'] !== data_get($plan, 'queue_payload.desired.nickname'),
                ],
                'roles' => [
                    'add_count' => count(data_get($plan, 'queue_payload.desired.roles.add', [])),
                    'remove_count' => count(data_get($plan, 'queue_payload.desired.roles.remove', [])),
                    'managed_count' => count(data_get($plan, 'queue_payload.desired.roles.managed', [])),
                ],
                'change_count' => $plan['change_count'],
            ],
            'warnings' => $plan['warnings'],
        ];
    }

    /** @param array<string, mixed> $intentPayload */
    public function confirm(
        User $actor,
        DiscordAccount $discordAccount,
        DiscordConnectionContext $connection,
        array $intentPayload,
    ): DiscordQueue {
        $this->assertCapability($connection);
        $observed = $intentPayload['observed'] ?? null;
        if (! is_array($observed)
            || ! array_key_exists('nickname', $observed)
            || ! isset($observed['role_ids'])
            || ! is_array($observed['role_ids'])) {
            throw new DiscordProfileSyncException(
                'profile_sync_intent_invalid',
                'This profile synchronization preview is invalid.',
                409,
                'Run /me and preview profile synchronization again.',
            );
        }

        $plan = $this->plan(
            $actor,
            $discordAccount,
            $connection,
            is_string($observed['nickname']) ? $observed['nickname'] : null,
            $observed['role_ids'],
        );
        $resourceVersion = $intentPayload['resource_version'] ?? null;
        $planHash = $intentPayload['plan_hash'] ?? null;
        if (! is_string($resourceVersion)
            || ! hash_equals($plan['resource_version'], $resourceVersion)
            || ! is_string($planHash)
            || ! hash_equals($plan['plan_hash'], $planHash)) {
            throw new DiscordProfileSyncException(
                'profile_sync_stale',
                'Your Nexus profile or managed-role policy changed after this preview.',
                409,
                'Run /me and preview profile synchronization again.',
            );
        }

        $queue = $this->queue->enqueue(
            action: self::ACTION,
            payload: $plan['queue_payload'],
            dedupeKey: 'member-profile-sync:'.$actor->getKey().':'.$plan['resource_version'].':'.$plan['plan_hash'],
            lane: DiscordQueueLane::SideEffects,
            priority: 70,
            guildId: $connection->guildId,
            connection: $connection,
        );

        $this->audit->recordAfterCommit(
            category: 'discord',
            action: 'member_profile_sync_queued',
            outcome: 'success',
            severity: 'info',
            subject: $actor,
            context: ['data' => [
                'queue_id' => $queue->getKey(),
                'connection_id' => $connection->connectionId,
                'connection_generation' => $connection->generation,
                'guild_id' => $connection->guildId,
                'profile_revision' => $plan['resource_version'],
                'nickname_change' => $plan['observed']['nickname'] !== data_get($plan, 'queue_payload.desired.nickname'),
                'roles_added' => count(data_get($plan, 'queue_payload.desired.roles.add', [])),
                'roles_removed' => count(data_get($plan, 'queue_payload.desired.roles.remove', [])),
            ]],
            message: 'Discord member profile synchronization queued.',
            actorOverride: [
                'type' => 'user',
                'id' => (int) $actor->getKey(),
                'name' => $actor->name,
            ],
        );

        return $queue;
    }

    /** @return array{state: string, label: string, checked_at: string, issues: list<string>} */
    public function status(
        User $actor,
        DiscordAccount $discordAccount,
        DiscordConnectionContext $connection,
    ): array {
        $checkedAt = now();
        if (! $connection->supportsQueueAction(self::ACTION)) {
            return [
                'state' => 'unavailable',
                'label' => 'This Discord installation does not support safe profile synchronization.',
                'checked_at' => $checkedAt->toIso8601String(),
                'issues' => ['Ask a server administrator to update the Nexus Discord integration.'],
            ];
        }

        try {
            $this->assertEligibleActor($actor, $discordAccount);
        } catch (DiscordProfileSyncException $exception) {
            return [
                'state' => 'unavailable',
                'label' => $exception->getMessage(),
                'checked_at' => $checkedAt->toIso8601String(),
                'issues' => array_values(array_filter([$exception->userAction])),
            ];
        }

        $latest = DiscordQueue::query()
            ->where('action', self::ACTION)
            ->where('connection_id', $connection->connectionId)
            ->where('guild_id', $connection->guildId)
            ->where('dedupe_key', 'like', 'member-profile-sync:'.$actor->getKey().':%')
            ->latest('created_at')
            ->first();

        if (! $latest) {
            return [
                'state' => 'available',
                'label' => 'Profile synchronization is available but has not been run from Discord yet.',
                'checked_at' => $checkedAt->toIso8601String(),
                'issues' => [],
            ];
        }

        $checkedAt = $latest->completed_at ?? $latest->updated_at ?? $checkedAt;

        return match ($latest->status) {
            DiscordQueueStatus::Pending, DiscordQueueStatus::Processing => [
                'state' => 'pending',
                'label' => 'Discord profile synchronization is queued or in progress.',
                'checked_at' => $checkedAt->toIso8601String(),
                'issues' => [],
            ],
            DiscordQueueStatus::Complete => [
                'state' => 'synced',
                'label' => 'The latest Discord profile synchronization completed.',
                'checked_at' => $checkedAt->toIso8601String(),
                'issues' => [],
            ],
            DiscordQueueStatus::Failed => [
                'state' => 'attention',
                'label' => 'The latest Discord profile synchronization needs attention.',
                'checked_at' => $checkedAt->toIso8601String(),
                'issues' => [$this->failureGuidance((string) data_get($latest->last_error, 'reason', 'unknown'))],
            ],
        };
    }

    /**
     * @param  list<string>  $observedRoleIds
     * @return array<string, mixed>
     */
    private function plan(
        User $actor,
        DiscordAccount $discordAccount,
        DiscordConnectionContext $connection,
        ?string $observedNickname,
        array $observedRoleIds,
    ): array {
        $nation = $this->assertEligibleActor($actor, $discordAccount);
        $position = AlliancePositionEnum::tryFrom((string) $nation->alliance_position);
        $isApplicant = $position === AlliancePositionEnum::APPLICANT;
        $applicantRoleId = $this->configuredRole($this->applicationSettings->getDiscordApplicantRoleId());
        $memberRoleId = $this->configuredRole($this->applicationSettings->getDiscordMemberRoleId());
        $cityRoles = DiscordCityTierRole::query()->orderBy('bucket_start')->get();
        $managedRoleIds = collect([$applicantRoleId, $memberRoleId])
            ->merge($cityRoles->map(fn (DiscordCityTierRole $role): ?string => $this->configuredRole($role->discord_role_id)))
            ->filter()
            ->unique()
            ->sort()
            ->values();
        if ($managedRoleIds->count() > 100) {
            throw new DiscordProfileSyncException(
                'profile_role_configuration_too_large',
                'Nexus has too many managed Discord roles for self-service synchronization.',
                409,
                'Ask a server administrator to review Discord role mappings.',
            );
        }

        $warnings = [];
        $desiredRoleIds = collect();
        if ($isApplicant) {
            if ($applicantRoleId) {
                $desiredRoleIds->push($applicantRoleId);
            } else {
                $warnings[] = 'The Nexus applicant role is not configured.';
            }
        } else {
            if ($memberRoleId) {
                $desiredRoleIds->push($memberRoleId);
            } else {
                $warnings[] = 'The Nexus member role is not configured.';
            }
            $cityRole = $cityRoles->first(fn (DiscordCityTierRole $role): bool => (int) $nation->num_cities >= $role->bucket_start
                && (int) $nation->num_cities <= $role->bucket_end);
            $cityRoleId = $cityRole ? $this->configuredRole($cityRole->discord_role_id) : null;
            if ($cityRoleId) {
                $desiredRoleIds->push($cityRoleId);
            } else {
                $warnings[] = 'No synchronized Discord city-tier role is available for this nation.';
            }
        }
        $desiredRoleIds = $desiredRoleIds->unique()->sort()->values();
        $observedRoleIds = collect($observedRoleIds)
            ->filter(fn (mixed $roleId): bool => is_string($roleId) && $this->isSnowflake($roleId))
            ->map(fn (string $roleId): string => trim($roleId))
            ->unique()
            ->sort()
            ->values();
        $desiredNickname = $this->desiredNickname($actor, $nation);
        $roleAdditions = $desiredRoleIds->diff($observedRoleIds)->values();
        $roleRemovals = $observedRoleIds->intersect($managedRoleIds)->diff($desiredRoleIds)->values();

        $policySnapshot = [
            'actor_id' => (int) $actor->getKey(),
            'discord_account_id' => (int) $discordAccount->getKey(),
            'nation' => [
                'id' => (int) $nation->getKey(),
                'alliance_id' => (int) $nation->alliance_id,
                'alliance_position' => (string) $nation->alliance_position,
                'leader_name' => (string) $nation->leader_name,
                'nation_name' => (string) $nation->nation_name,
                'num_cities' => (int) $nation->num_cities,
                'updated_at' => $nation->updated_at?->toIso8601String(),
            ],
            'roles' => [
                'managed' => $managedRoleIds->all(),
                'desired' => $desiredRoleIds->all(),
            ],
        ];
        $resourceVersion = hash('sha256', CanonicalJson::encode($policySnapshot));
        $queuePayload = [
            'contract_version' => 1,
            'installation' => [
                'application_id' => $connection->applicationId,
                'guild_id' => $connection->guildId,
                'connection_id' => $connection->connectionId,
                'generation' => $connection->generation,
            ],
            'member' => [
                'discord_user_id' => (string) $discordAccount->discord_id,
                'nexus_user_id' => (int) $actor->getKey(),
                'nation_id' => (int) $nation->getKey(),
                'profile_revision' => $resourceVersion,
            ],
            'desired' => [
                'nickname' => $desiredNickname,
                'roles' => [
                    'managed' => $managedRoleIds->all(),
                    'add' => $roleAdditions->all(),
                    'remove' => $roleRemovals->all(),
                ],
            ],
        ];
        $observed = [
            'nickname' => $observedNickname,
            'role_ids' => $observedRoleIds->all(),
        ];

        return [
            'resource_version' => $resourceVersion,
            'plan_hash' => hash('sha256', CanonicalJson::encode($queuePayload['desired'])),
            'queue_payload' => $queuePayload,
            'observed' => $observed,
            'warnings' => $warnings,
            'change_count' => ($observedNickname === $desiredNickname ? 0 : 1)
                + $roleAdditions->count()
                + $roleRemovals->count(),
        ];
    }

    private function assertCapability(DiscordConnectionContext $connection): void
    {
        if (! $connection->supportsQueueAction(self::ACTION)) {
            throw new DiscordProfileSyncException(
                'profile_sync_unavailable',
                'This Discord installation cannot safely synchronize member profiles yet.',
                409,
                'Ask a server administrator to update the Nexus Discord integration.',
            );
        }
    }

    private function assertEligibleActor(User $actor, DiscordAccount $discordAccount): Nation
    {
        if ((int) $discordAccount->user_id !== (int) $actor->getKey()
            || $discordAccount->unlinked_at !== null
            || ! $actor->isVerified()
            || $actor->disabled) {
            throw new DiscordProfileSyncException(
                'profile_sync_actor_ineligible',
                'This Nexus account is not eligible for Discord profile synchronization.',
                403,
                'Review your Nexus account-link and verification status.',
            );
        }

        $nation = $actor->nation()->first();
        $position = $nation ? AlliancePositionEnum::tryFrom((string) $nation->alliance_position) : null;
        $eligiblePositions = [
            AlliancePositionEnum::APPLICANT,
            AlliancePositionEnum::MEMBER,
            AlliancePositionEnum::OFFICER,
            AlliancePositionEnum::HEIR,
            AlliancePositionEnum::LEADER,
        ];
        if (! $nation
            || ! $this->allianceMembership->contains((int) $nation->alliance_id)
            || ! in_array($position, $eligiblePositions, true)) {
            throw new DiscordProfileSyncException(
                'profile_sync_membership_ineligible',
                'Nexus does not currently recognize this nation as an alliance member or applicant.',
                409,
                'Wait for nation data to synchronize or contact a Nexus administrator.',
            );
        }

        return $nation;
    }

    private function configuredRole(?string $roleId): ?string
    {
        $roleId = trim((string) $roleId);
        if ($roleId === '') {
            return null;
        }
        if (! $this->isSnowflake($roleId)) {
            throw new DiscordProfileSyncException(
                'profile_role_configuration_invalid',
                'A Nexus-managed Discord role mapping is invalid.',
                409,
                'Ask a server administrator to review Discord role mappings.',
            );
        }

        return $roleId;
    }

    private function desiredNickname(User $actor, Nation $nation): string
    {
        $candidate = trim((string) ($nation->leader_name ?: $nation->nation_name ?: $actor->name));
        $candidate = preg_replace('/[\x00-\x1F\x7F]/u', '', $candidate) ?? '';
        $candidate = Str::limit(trim($candidate), 32, '');
        if ($candidate === '') {
            throw new DiscordProfileSyncException(
                'profile_nickname_unavailable',
                'Nexus could not calculate a safe Discord nickname for this nation.',
                409,
                'Update the nation leader name in Politics & War and allow Nexus to synchronize it.',
            );
        }

        return $candidate;
    }

    private function isSnowflake(string $value): bool
    {
        return preg_match('/^\d{17,20}$/', trim($value)) === 1;
    }

    private function failureGuidance(string $reason): string
    {
        return match ($reason) {
            'missing_discord_permission' => 'The bot is missing permission to edit nicknames or roles.',
            'member_unmanageable', 'role_unmanageable' => 'The bot role is below the member or a Nexus-managed role.',
            'member_unavailable' => 'The linked Discord member is no longer available in this server.',
            'role_not_found' => 'A Nexus-managed Discord role no longer exists.',
            'stale_connection_generation', 'wrong_connection' => 'The Discord installation changed while synchronization was running.',
            default => 'Retry from /me. If the problem continues, ask a server administrator to run /nexus status.',
        };
    }
}
