<?php

namespace App\Services;

use App\Services\War\RaidLeaderboardService;
use Carbon\Carbon;

class LeaderboardDirectoryService
{
    public function __construct(
        private readonly NationProfitabilityService $nationProfitabilityService,
        private readonly RaidLeaderboardService $raidLeaderboardService
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getPageData(
        ?string $requestedBoard = null,
        ?string $from = null,
        ?string $to = null,
        ?int $viewerNationId = null
    ): array {
        $boards = $this->boards();
        $defaultSlug = 'dashboard';
        $activeSlug = array_key_exists((string) $requestedBoard, $boards) ? (string) $requestedBoard : $defaultSlug;
        [$fromDate, $toDate] = $this->resolveRaidDateRange($from, $to);
        $payloads = [
            'profitability' => $this->nationProfitabilityService->getLeaderboard(),
            'raid-performance' => $this->raidLeaderboardService->buildLeaderboard($fromDate, $toDate, $viewerNationId),
        ];

        $boards = collect($boards)->map(function (array $board) use ($payloads): array {
            if ($board['slug'] === 'profitability') {
                $payload = $payloads['profitability'] ?? ['rows' => []];
                $rows = $payload['rows'] ?? [];
                $board['kpis'] = [
                    [
                        'label' => 'Ranked Nations',
                        'value' => number_format(count($rows)),
                    ],
                    [
                        'label' => 'Top Daily Profit',
                        'value' => isset($rows[0]['converted_profit_per_day']) ? '$'.number_format((float) $rows[0]['converted_profit_per_day'], 2) : 'N/A',
                    ],
                    [
                        'label' => 'Price Basis',
                        'value' => (string) ($payload['price_basis'] ?? '24h average'),
                    ],
                ];
                $topNation = $payload['rows'][0] ?? null;
                $board['champion'] = $topNation ? [
                    'nation_name' => $topNation['nation_name'],
                    'leader_name' => $topNation['leader_name'],
                    'metric_label' => 'Top Daily Profit',
                    'metric_value' => '$'.number_format((float) $topNation['converted_profit_per_day'], 2),
                    'nation_url' => $topNation['nation_url'],
                ] : null;
            }

            if ($board['slug'] === 'raid-performance') {
                $payload = $payloads['raid-performance'] ?? [];
                $topRaider = $payload['totals']['top_looter'] ?? null;
                $board['kpis'] = [
                    [
                        'label' => 'Loot Value',
                        'value' => '$'.number_format((float) ($payload['totals']['loot_value'] ?? 0), 0),
                    ],
                    [
                        'label' => 'Victories',
                        'value' => number_format((int) ($payload['totals']['victories'] ?? 0)),
                    ],
                    [
                        'label' => 'Window',
                        'value' => sprintf('%s to %s', $payload['filters']['from'] ?? 'N/A', $payload['filters']['to'] ?? 'N/A'),
                    ],
                ];
                $board['champion'] = $topRaider ? [
                    'nation_name' => $topRaider['nation_name'],
                    'leader_name' => $topRaider['leader_name'],
                    'metric_label' => 'Top Loot Value',
                    'metric_value' => '$'.number_format((float) ($topRaider['loot_value'] ?? 0), 0),
                    'nation_url' => sprintf('https://politicsandwar.com/nation/id=%d', (int) $topRaider['id']),
                ] : null;
            }

            return $board;
        })->keyBy('slug')->all();

        return [
            'boards' => $boards,
            'activeBoard' => $boards[$activeSlug],
            'activePayload' => $payloads[$activeSlug] ?? null,
            'dashboardBoards' => collect($boards)
                ->where('status', 'live')
                ->reject(fn (array $board): bool => $board['slug'] === 'dashboard')
                ->values()
                ->all(),
            'liveBoards' => collect($boards)
                ->where('status', 'live')
                ->reject(fn (array $board): bool => $board['slug'] === 'dashboard')
                ->values()
                ->all(),
            'plannedBoards' => collect($boards)->where('status', 'planned')->values()->all(),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function boards(): array
    {
        return [
            'dashboard' => [
                'slug' => 'dashboard',
                'status' => 'live',
                'eyebrow' => 'Overview',
                'name' => 'Dashboard',
                'title' => 'Leaderboard dashboard',
                'description' => 'See the top nation from each live leaderboard, then jump directly into the board that matters.',
                'summary' => 'Your fastest way to scan alliance standouts across economics and raiding.',
                'accent' => 'emerald',
                'icon' => 'D',
            ],
            'profitability' => [
                'slug' => 'profitability',
                'status' => 'live',
                'eyebrow' => 'Economy',
                'name' => 'Profitability',
                'title' => 'Daily nation profitability',
                'description' => 'See which nations are turning their cities, projects, and military profile into the strongest net daily profit.',
                'summary' => 'Best for spotting economic strength, weak manufacturing margins, and nations that need a build review.',
                'accent' => 'emerald',
                'icon' => '$',
                'partial' => 'leaderboards.partials.profitability',
            ],
            'raid-performance' => [
                'slug' => 'raid-performance',
                'status' => 'live',
                'eyebrow' => 'Defense',
                'name' => 'Raid Performance',
                'title' => 'Raid loot and efficiency',
                'description' => 'Compare total loot, loot rate, infra damage, and raid finishing efficiency in one board family.',
                'summary' => 'Ideal for finding your best raiders and the nations extracting the most value per war.',
                'accent' => 'amber',
                'icon' => 'R',
                'partial' => 'leaderboards.partials.raid-performance',
            ],
            'military-readiness' => [
                'slug' => 'military-readiness',
                'status' => 'planned',
                'eyebrow' => 'Readiness',
                'name' => 'Military Readiness',
                'title' => 'MMR and force readiness',
                'description' => 'Surface nations with the strongest standing military posture and flag the ones drifting below target.',
                'summary' => 'Useful for quick war-room triage and member compliance checks.',
                'accent' => 'sky',
                'icon' => 'M',
                'kpis' => [
                    ['label' => 'Stage', 'value' => 'Planned'],
                    ['label' => 'Focus', 'value' => 'MMR and unit strength'],
                    ['label' => 'Primary Use', 'value' => 'Readiness checks'],
                ],
            ],
            'city-growth' => [
                'slug' => 'city-growth',
                'status' => 'planned',
                'eyebrow' => 'Growth',
                'name' => 'City Growth',
                'title' => 'Expansion and development',
                'description' => 'Track who is adding cities, climbing infra, and compounding their nation fastest over time.',
                'summary' => 'Useful for promotions, grant targeting, and seeing who is really scaling.',
                'accent' => 'rose',
                'icon' => 'C',
                'kpis' => [
                    ['label' => 'Stage', 'value' => 'Planned'],
                    ['label' => 'Focus', 'value' => 'Cities and infra'],
                    ['label' => 'Primary Use', 'value' => 'Growth tracking'],
                ],
            ],
            'market-trading' => [
                'slug' => 'market-trading',
                'status' => 'planned',
                'eyebrow' => 'Trade',
                'name' => 'Market Trading',
                'title' => 'Trading wins and volume',
                'description' => 'Highlight nations that are consistently moving resources well and extracting strong market value.',
                'summary' => 'A natural home for future trading streaks, volume, and spread-capture boards.',
                'accent' => 'violet',
                'icon' => 'T',
                'kpis' => [
                    ['label' => 'Stage', 'value' => 'Planned'],
                    ['label' => 'Focus', 'value' => 'Volume and margins'],
                    ['label' => 'Primary Use', 'value' => 'Trade insight'],
                ],
            ],
            'contribution' => [
                'slug' => 'contribution',
                'status' => 'planned',
                'eyebrow' => 'Alliance',
                'name' => 'Contribution',
                'title' => 'Alliance support and contribution',
                'description' => 'Rank members by taxes, deposits, reimbursements, and net alliance impact.',
                'summary' => 'Good for leadership visibility, recognition, and spotting lopsided participation.',
                'accent' => 'orange',
                'icon' => 'A',
                'kpis' => [
                    ['label' => 'Stage', 'value' => 'Planned'],
                    ['label' => 'Focus', 'value' => 'Deposits and taxes'],
                    ['label' => 'Primary Use', 'value' => 'Alliance impact'],
                ],
            ],
        ];
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveRaidDateRange(?string $from, ?string $to): array
    {
        $fromDate = $from ? Carbon::parse($from)->startOfDay() : now()->subDays(30)->startOfDay();
        $toDate = $to ? Carbon::parse($to)->endOfDay() : now()->endOfDay();

        if ($fromDate->greaterThan($toDate)) {
            [$fromDate, $toDate] = [$toDate->copy()->startOfDay(), $fromDate->copy()->endOfDay()];
        }

        return [$fromDate, $toDate];
    }
}
