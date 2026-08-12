<?php

namespace App\Services\Discord;

use App\Enums\ApplicationStatus;
use App\Enums\DiscordActorContextState;
use App\Models\Application;
use App\Models\User;
use App\Services\PendingRequestsService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Throwable;

final readonly class DiscordMemberSummaryService
{
    /** @var array<string, string> */
    private const CAPABILITY_LABELS = [
        'manage-applications' => 'Review applications',
        'view-audits' => 'View audit findings',
        'manage-audits' => 'Manage audit findings',
        'view-members' => 'View the member directory',
        'view-wars' => 'View war information',
        'manage-war-room' => 'Manage Milcom operations',
        'view-diagnostic-info' => 'View integration diagnostics',
    ];

    public function __construct(private PendingRequestsService $pendingRequests) {}

    /**
     * @param  array{state: string, label: string, checked_at: string, issues: list<string>}  $profileSync
     * @return array<string, mixed>
     */
    public function summarize(
        DiscordActorContext $context,
        int $capabilityRevision,
        array $profileSync,
    ): array {
        if (! $context->isReady()) {
            return $context->safePayload();
        }

        if ($capabilityRevision < 1 || ! $context->actor || ! $context->discordAccount) {
            throw new InvalidArgumentException('A ready Discord actor context and capability revision are required.');
        }
        $this->validateProfileSync($profileSync);

        $actor = $context->actor;
        $discordAccount = $context->discordAccount;
        $nation = $actor->nation()->with('alliance')->first();
        if (! $nation) {
            return (new DiscordActorContext(
                state: DiscordActorContextState::NoNation,
                message: 'The linked Nexus account has no synchronized nation profile.',
                actor: $actor,
                discordAccount: $discordAccount,
                userAction: [
                    'label' => 'Open Nexus dashboard',
                    'deep_link_path' => route('user.dashboard', absolute: false),
                ],
            ))->safePayload();
        }

        $generatedAt = now();
        $openWork = $this->openWork($actor, $generatedAt);
        $sourceUpdatedAt = collect([$actor->updated_at, $discordAccount->updated_at, $nation->updated_at])
            ->filter(fn (mixed $timestamp): bool => $timestamp instanceof CarbonInterface)
            ->sortDesc()
            ->first() ?? $generatedAt;
        $staleAfterSeconds = max(60, (int) config('services.discord.summary_stale_after_seconds', 1800));

        return [
            'contract_version' => 1,
            'state' => 'ready',
            'message' => 'Your linked Nexus account summary is ready.',
            'identity' => [
                'display_name' => $actor->name,
                'discord_username' => $discordAccount->discord_username,
                'link_state' => 'linked',
                'linked_at' => ($discordAccount->linked_at ?? $discordAccount->created_at)->toIso8601String(),
                'deep_link_path' => route('user.settings', absolute: false),
            ],
            'nation' => [
                'id' => $nation->id,
                'name' => $nation->nation_name,
                'leader_name' => $nation->leader_name,
                'deep_link_path' => route('user.dashboard', absolute: false),
            ],
            'alliance' => $nation->alliance ? [
                'id' => $nation->alliance->id,
                'name' => $nation->alliance->name,
                'deep_link_path' => route('user.dashboard', absolute: false),
            ] : null,
            'capabilities' => [
                'items' => $this->capabilities($actor),
                'revision' => $capabilityRevision,
            ],
            'open_work' => $openWork,
            'profile_sync' => $profileSync,
            'freshness' => [
                'state' => $sourceUpdatedAt->lt($generatedAt->copy()->subSeconds($staleAfterSeconds))
                    ? 'stale'
                    : 'fresh',
                'generated_at' => $generatedAt->toIso8601String(),
                'source_updated_at' => $sourceUpdatedAt->toIso8601String(),
            ],
            'links' => [
                'profile' => route('user.settings', absolute: false),
                'application' => route('apply.show', absolute: false),
                'audit' => route('audit.index', absolute: false),
            ],
        ];
    }

    /** @return list<array{key: string, label: string}> */
    private function capabilities(User $actor): array
    {
        $items = [[
            'key' => 'member.self',
            'label' => 'Use member self-service workflows',
        ]];

        foreach (self::CAPABILITY_LABELS as $permission => $label) {
            if (Gate::forUser($actor)->allows($permission)) {
                $items[] = [
                    'key' => str_replace('-', '.', $permission),
                    'label' => $label,
                ];
            }
        }

        return $items;
    }

    /** @return array{total: int, by_type: array<string, int>, complete: bool, generated_at: string} */
    private function openWork(User $actor, CarbonInterface $generatedAt): array
    {
        $ownApplications = Application::query()
            ->where('nation_id', $actor->nation_id)
            ->where('status', ApplicationStatus::Pending->value)
            ->count();
        $byType = ['applications_own' => $ownApplications];
        $complete = true;

        try {
            $staff = $this->pendingRequests->getCountsForUser($actor);
            if ($staff['can_view']) {
                foreach ($staff['counts'] as $type => $count) {
                    $byType['staff_'.$type] = max(0, (int) $count);
                }
            }
            $complete = (bool) $staff['complete'];
            $generatedAt = CarbonImmutable::parse($staff['generated_at']);
        } catch (Throwable) {
            $complete = false;
        }

        return [
            'total' => array_sum($byType),
            'by_type' => $byType,
            'complete' => $complete,
            'generated_at' => $generatedAt->toIso8601String(),
        ];
    }

    /** @param array<string, mixed> $profileSync */
    private function validateProfileSync(array $profileSync): void
    {
        foreach (['state', 'label', 'checked_at'] as $field) {
            if (! isset($profileSync[$field]) || ! is_string($profileSync[$field]) || trim($profileSync[$field]) === '') {
                throw new InvalidArgumentException('A complete profile synchronization snapshot is required.');
            }
        }
        if (! isset($profileSync['issues']) || ! is_array($profileSync['issues'])
            || collect($profileSync['issues'])->contains(fn (mixed $issue): bool => ! is_string($issue))) {
            throw new InvalidArgumentException('A complete profile synchronization snapshot is required.');
        }
    }
}
