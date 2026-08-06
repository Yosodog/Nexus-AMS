<?php

namespace App\Services;

use App\DataTransferObjects\RaidPolicyEvaluation;
use App\Models\Alliance;
use App\Models\NoRaidList;
use App\Models\Treaty;
use Illuminate\Support\Collection;

class RaidPolicyService
{
    public function __construct(private readonly AllianceMembershipService $membershipService) {}

    public function evaluateAlliance(?int $allianceId): RaidPolicyEvaluation
    {
        $snapshot = $this->snapshot();

        if ($allianceId === null || $allianceId <= 0) {
            return new RaidPolicyEvaluation(true, $snapshot['top_cap'], []);
        }

        $defender = Alliance::query()->find($allianceId, ['id', 'name']);

        if ($defender === null) {
            return new RaidPolicyEvaluation(true, $snapshot['top_cap'], []);
        }

        $reasons = [];

        if (in_array($allianceId, $snapshot['member_alliance_ids'], true)) {
            $reasons[] = $this->reason(
                code: 'member_alliance',
                message: "{$defender->name} is one of our member alliances.",
                context: ['alliance_id' => $allianceId],
            );
        }

        if (in_array($allianceId, $snapshot['no_raid_alliance_ids'], true)) {
            $reasons[] = $this->reason(
                code: 'no_raid_list',
                message: "{$defender->name} is on the no-raid list.",
                context: ['alliance_id' => $allianceId],
            );
        }

        $topAlliancePosition = array_search($allianceId, $snapshot['top_alliance_ids'], true);

        if ($topAlliancePosition !== false) {
            $scoreRank = $topAlliancePosition + 1;
            $reasons[] = $this->reason(
                code: 'top_alliance_cap',
                message: "{$defender->name} is protected by the top {$snapshot['top_cap']} alliance cap (ranked #{$scoreRank} by score).",
                context: [
                    'alliance_id' => $allianceId,
                    'score_rank' => $scoreRank,
                    'top_alliance_cap' => $snapshot['top_cap'],
                ],
            );
        }

        $protectedPartnerIds = $this->protectedTreatyPartnerIds(
            $allianceId,
            $snapshot['top_alliance_ids'],
            $snapshot['treaties'],
        );

        if ($protectedPartnerIds !== []) {
            $partnerNames = Alliance::query()
                ->whereIn('id', $protectedPartnerIds)
                ->pluck('name', 'id');
            $protectedPartners = collect($protectedPartnerIds)
                ->map(fn (int $partnerId): array => [
                    'alliance_id' => $partnerId,
                    'alliance_name' => $partnerNames->get($partnerId, "Alliance #{$partnerId}"),
                ])
                ->values()
                ->all();
            $partnerLabel = collect($protectedPartners)->pluck('alliance_name')->join(', ');

            $reasons[] = $this->reason(
                code: 'protected_treaty',
                message: "{$defender->name} has a treaty with protected top alliance {$partnerLabel}.",
                context: [
                    'alliance_id' => $allianceId,
                    'protected_partners' => $protectedPartners,
                ],
            );
        }

        return new RaidPolicyEvaluation($reasons === [], $snapshot['top_cap'], $reasons);
    }

    /**
     * @return list<int>
     */
    public function raidableAllianceIds(): array
    {
        $snapshot = $this->snapshot();
        $eligibleAllianceIds = Alliance::query()
            ->whereNotIn('id', $snapshot['top_alliance_ids'])
            ->whereNotIn('id', $snapshot['member_alliance_ids'])
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return collect($eligibleAllianceIds)
            ->reject(fn (int $allianceId): bool => in_array(
                $allianceId,
                $snapshot['no_raid_alliance_ids'],
                true,
            ))
            ->reject(fn (int $allianceId): bool => $this->protectedTreatyPartnerIds(
                $allianceId,
                $snapshot['top_alliance_ids'],
                $snapshot['treaties'],
            ) !== [])
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array{
     *     top_cap: int,
     *     top_alliance_ids: list<int>,
     *     member_alliance_ids: list<int>,
     *     no_raid_alliance_ids: list<int>,
     *     treaties: Collection<int, Treaty>
     * }
     */
    private function snapshot(): array
    {
        $topCap = SettingService::getTopRaidable();

        return [
            'top_cap' => $topCap,
            'top_alliance_ids' => Alliance::query()
                ->orderByDesc('score')
                ->take($topCap)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all(),
            'member_alliance_ids' => $this->membershipService
                ->getAllianceIds()
                ->map(fn ($id): int => (int) $id)
                ->values()
                ->all(),
            'no_raid_alliance_ids' => NoRaidList::query()
                ->pluck('alliance_id')
                ->map(fn ($id): int => (int) $id)
                ->all(),
            'treaties' => Treaty::query()->orderBy('id')->get(),
        ];
    }

    /**
     * @param  list<int>  $topAllianceIds
     * @param  Collection<int, Treaty>  $treaties
     * @return list<int>
     */
    private function protectedTreatyPartnerIds(
        int $allianceId,
        array $topAllianceIds,
        Collection $treaties,
    ): array {
        return $treaties
            ->map(function (Treaty $treaty) use ($allianceId, $topAllianceIds): ?int {
                $firstAllianceId = (int) $treaty->alliance1_id;
                $secondAllianceId = (int) $treaty->alliance2_id;

                if ($firstAllianceId === $allianceId && in_array($secondAllianceId, $topAllianceIds, true)) {
                    return $secondAllianceId;
                }

                if ($secondAllianceId === $allianceId && in_array($firstAllianceId, $topAllianceIds, true)) {
                    return $firstAllianceId;
                }

                return null;
            })
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{code: string, message: string, context: array<string, mixed>}
     */
    private function reason(string $code, string $message, array $context): array
    {
        return compact('code', 'message', 'context');
    }
}
