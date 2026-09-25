<?php

namespace App\Services;

use App\DataTransferObjects\RaidPolicyEvaluation;
use App\Models\Alliance;
use App\Models\NoRaidList;
use App\Models\Treaty;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class RaidPolicyService
{
    private const VERSION_KEY = 'raid-policy:version';

    private const PROTECTED_CACHE_MINUTES = 5;

    /** @var list<int>|null */
    private ?array $protectedAllianceIds = null;

    public function __construct(private readonly AllianceMembershipService $membershipService) {}

    /**
     * Current raid policy version; finder caches include it in their keys.
     */
    public function version(): int
    {
        return max((int) Cache::get(self::VERSION_KEY, 1), 1);
    }

    /**
     * Invalidate every cached finder result built under the previous policy.
     */
    public function bumpVersion(): void
    {
        if (Cache::add(self::VERSION_KEY, 2, now()->addYears(10))) {
            return;
        }

        Cache::increment(self::VERSION_KEY);
    }

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
     * Every alliance whose members may not be raided: the top alliances by score,
     * member alliances, the no-raid list, and treaty partners of a top alliance.
     * Cached briefly per policy version; admin policy changes bump the version.
     *
     * @return list<int>
     */
    public function protectedAllianceIds(): array
    {
        return $this->protectedAllianceIds ??= Cache::remember(
            'raid-policy:protected:'.$this->version(),
            now()->addMinutes(self::PROTECTED_CACHE_MINUTES),
            fn (): array => $this->resolveProtectedAllianceIds(),
        );
    }

    /**
     * @return list<int>
     */
    private function resolveProtectedAllianceIds(): array
    {
        $topAllianceIds = $this->topAllianceIds(SettingService::getTopRaidable());
        $noRaidAndTreatyPartners = NoRaidList::query()
            ->toBase()
            ->select('alliance_id')
            ->union(Treaty::query()->toBase()->select('alliance2_id')->whereIn('alliance1_id', $topAllianceIds))
            ->union(Treaty::query()->toBase()->select('alliance1_id')->whereIn('alliance2_id', $topAllianceIds))
            ->pluck('alliance_id');

        return collect([
            ...$topAllianceIds,
            ...$this->membershipService->getAllianceIds()->all(),
            ...$noRaidAndTreatyPartners->all(),
        ])
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    private function topAllianceIds(int $topCap): array
    {
        return Alliance::query()
            ->orderByDesc('score')
            ->take($topCap)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
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
            'top_alliance_ids' => $this->topAllianceIds($topCap),
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
