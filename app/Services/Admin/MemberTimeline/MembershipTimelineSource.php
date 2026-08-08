<?php

namespace App\Services\Admin\MemberTimeline;

use App\DataTransferObjects\Admin\MemberTimelineItem;
use App\Enums\MemberTimelineCategory;
use App\Models\DiscordAccount;
use App\Models\MemberInactivityException;
use App\Models\Nation;
use App\Models\User;
use App\Services\RoleDelegationService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;

final class MembershipTimelineSource implements MemberTimelineSource
{
    public function __construct(
        private readonly RoleDelegationService $roleDelegationService,
    ) {}

    public function category(): MemberTimelineCategory
    {
        return MemberTimelineCategory::Membership;
    }

    public function visibleTo(User $viewer): bool
    {
        return $viewer->can('view-members');
    }

    public function items(Nation $nation, User $viewer, int $recordLimit): Collection
    {
        $items = collect();

        if ($nation->created_at !== null) {
            $items->push(new MemberTimelineItem(
                sourceKey: "nation:{$nation->id}:observed",
                deduplicationKey: "nation:{$nation->id}:observed",
                category: $this->category(),
                occurredAt: CarbonImmutable::instance($nation->created_at),
                actorKind: 'system',
                actorLabel: 'Politics & War synchronization',
                summary: 'Nation record entered Nexus.',
                statusLabel: 'Observed',
                statusIntent: 'neutral',
                statusIcon: 'eye',
                sourceUrl: "https://politicsandwar.com/nation/id={$nation->id}",
                sourceLabel: "nation #{$nation->id}",
            ));
        }

        if ($viewer->can('manage-member-exceptions')) {
            $items->push(...$this->inactivityExceptionItems($nation, $recordLimit));
        }

        if (! $viewer->can('edit-users')) {
            return $items;
        }

        $user = User::query()
            ->without('roles')
            ->select(['id', 'nation_id'])
            ->where('nation_id', $nation->id)
            ->first();

        if ($user === null) {
            return $items;
        }

        try {
            $this->roleDelegationService->ensureCanManageUser($viewer, $user);
        } catch (AuthorizationException) {
            return $items;
        }

        $user = User::query()
            ->without('roles')
            ->select(['id', 'nation_id', 'created_at', 'verified_at'])
            ->findOrFail($user->id);

        if ($user->created_at !== null) {
            $items->push(new MemberTimelineItem(
                sourceKey: "user:{$user->id}:created",
                deduplicationKey: "user:{$user->id}:created",
                category: $this->category(),
                occurredAt: CarbonImmutable::instance($user->created_at),
                actorKind: 'member',
                actorLabel: 'Nexus account holder',
                summary: 'Nexus account created.',
                statusLabel: 'Created',
                statusIntent: 'active',
                statusIcon: 'check-circle',
                sourceUrl: route('admin.users.edit', ['user' => $user->id]),
                sourceLabel: "user #{$user->id}",
            ));
        }

        if ($user->verified_at !== null) {
            $items->push(new MemberTimelineItem(
                sourceKey: "user:{$user->id}:verified",
                deduplicationKey: "user:{$user->id}:verified",
                category: $this->category(),
                occurredAt: CarbonImmutable::instance($user->verified_at),
                actorKind: 'system',
                actorLabel: 'Account verification',
                summary: 'Nexus account verification completed.',
                statusLabel: 'Verified',
                statusIntent: 'success',
                statusIcon: 'check-circle',
                sourceUrl: route('admin.users.edit', ['user' => $user->id]),
                sourceLabel: "user #{$user->id}",
            ));
        }

        DiscordAccount::query()
            ->withTrashed()
            ->select(['id', 'user_id', 'linked_at', 'unlinked_at'])
            ->where('user_id', $user->id)
            ->latest('linked_at')
            ->limit($recordLimit)
            ->get()
            ->each(function (DiscordAccount $account) use ($items, $user): void {
                if ($account->linked_at !== null) {
                    $items->push(new MemberTimelineItem(
                        sourceKey: "discord-account:{$account->id}:linked",
                        deduplicationKey: "discord-account:{$account->id}:linked",
                        category: $this->category(),
                        occurredAt: CarbonImmutable::instance($account->linked_at),
                        actorKind: 'member',
                        actorLabel: 'Nexus account holder',
                        summary: 'Discord account linked to Nexus.',
                        statusLabel: 'Linked',
                        statusIntent: 'success',
                        statusIcon: 'check-circle',
                        sourceUrl: route('admin.users.edit', ['user' => $user->id]),
                        sourceLabel: "user #{$user->id}",
                    ));
                }

                if ($account->unlinked_at !== null) {
                    $items->push(new MemberTimelineItem(
                        sourceKey: "discord-account:{$account->id}:unlinked",
                        deduplicationKey: "discord-account:{$account->id}:unlinked",
                        category: $this->category(),
                        occurredAt: CarbonImmutable::instance($account->unlinked_at),
                        actorKind: 'staff',
                        actorLabel: 'Account administration',
                        summary: 'Discord account unlinked from Nexus.',
                        statusLabel: 'Unlinked',
                        statusIntent: 'warning',
                        statusIcon: 'minus-circle',
                        sourceUrl: route('admin.users.edit', ['user' => $user->id]),
                        sourceLabel: "user #{$user->id}",
                    ));
                }
            });

        return $items;
    }

    /** @return Collection<int, MemberTimelineItem> */
    private function inactivityExceptionItems(Nation $nation, int $recordLimit): Collection
    {
        return MemberInactivityException::query()
            ->select([
                'id',
                'nation_id',
                'category',
                'approved_at',
                'last_reviewed_at',
                'expired_at',
                'revoked_at',
                'created_at',
                'updated_at',
            ])
            ->where('nation_id', $nation->id)
            ->latest('updated_at')
            ->limit($recordLimit)
            ->get()
            ->flatMap(function (MemberInactivityException $exception) use ($nation): array {
                $label = $exception->category->label();
                $url = route('admin.members.show', ['Nation' => $nation->id]).'#inactivity-exceptions';
                $approvedAt = $exception->approved_at ?? $exception->created_at;
                $items = [new MemberTimelineItem(
                    sourceKey: "member-inactivity-exception:{$exception->id}:approved",
                    deduplicationKey: "member-inactivity-exception:{$exception->id}:approved",
                    category: $this->category(),
                    occurredAt: CarbonImmutable::instance($approvedAt),
                    actorKind: 'staff',
                    actorLabel: 'Membership administration',
                    summary: "{$label} exception approved.",
                    statusLabel: 'Approved',
                    statusIntent: 'active',
                    statusIcon: 'check-circle',
                    sourceUrl: $url,
                    sourceLabel: "inactivity exception #{$exception->id}",
                    sourcePriority: 70,
                )];

                if ($exception->last_reviewed_at !== null
                    && ! $exception->last_reviewed_at->equalTo($approvedAt)) {
                    $items[] = new MemberTimelineItem(
                        sourceKey: "member-inactivity-exception:{$exception->id}:reviewed",
                        deduplicationKey: "member-inactivity-exception:{$exception->id}:reviewed",
                        category: $this->category(),
                        occurredAt: CarbonImmutable::instance($exception->last_reviewed_at),
                        actorKind: 'staff',
                        actorLabel: 'Membership administration',
                        summary: "{$label} exception reviewed or extended.",
                        statusLabel: 'Reviewed',
                        statusIntent: 'active',
                        statusIcon: 'pencil-square',
                        sourceUrl: $url,
                        sourceLabel: "inactivity exception #{$exception->id}",
                        sourcePriority: 75,
                    );
                }

                if ($exception->expired_at !== null) {
                    $items[] = new MemberTimelineItem(
                        sourceKey: "member-inactivity-exception:{$exception->id}:expired",
                        deduplicationKey: "member-inactivity-exception:{$exception->id}:expired",
                        category: $this->category(),
                        occurredAt: CarbonImmutable::instance($exception->expired_at),
                        actorKind: 'system',
                        actorLabel: 'Membership policy scheduler',
                        summary: "{$label} exception expired.",
                        statusLabel: 'Expired',
                        statusIntent: 'neutral',
                        statusIcon: 'archive-box',
                        sourceUrl: $url,
                        sourceLabel: "inactivity exception #{$exception->id}",
                        sourcePriority: 80,
                    );
                }

                if ($exception->revoked_at !== null) {
                    $items[] = new MemberTimelineItem(
                        sourceKey: "member-inactivity-exception:{$exception->id}:revoked",
                        deduplicationKey: "member-inactivity-exception:{$exception->id}:revoked",
                        category: $this->category(),
                        occurredAt: CarbonImmutable::instance($exception->revoked_at),
                        actorKind: 'staff',
                        actorLabel: 'Membership administration',
                        summary: "{$label} exception revoked.",
                        statusLabel: 'Revoked',
                        statusIntent: 'warning',
                        statusIcon: 'minus-circle',
                        sourceUrl: $url,
                        sourceLabel: "inactivity exception #{$exception->id}",
                        sourcePriority: 90,
                    );
                }

                return $items;
            })
            ->values();
    }
}
