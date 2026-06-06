<?php

namespace App\Http\Controllers;

use App\Models\Alliance;
use App\Services\AllianceMembershipService;
use App\Services\SettingService;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function __invoke(AllianceMembershipService $membershipService): View
    {
        $allianceId = $membershipService->getPrimaryAllianceId();
        $alliance = $allianceId ? Alliance::query()->find($allianceId) : null;

        $appName = config('app.name');
        $allianceName = $alliance?->name ?? $appName;

        $activeNationQuery = $alliance?->nations()
            ->where(function ($query) {
                $query->whereNull('alliance_position')
                    ->orWhere('alliance_position', '!=', 'APPLICANT');
            })
            ->where(function ($query) {
                $query->whereNull('vacation_mode_turns')
                    ->orWhere('vacation_mode_turns', '<=', 0);
            });

        $homeContent = [
            'headline' => SettingService::getHomepageHeadline($allianceName),
            'tagline' => SettingService::getHomepageTagline($allianceName),
            'about' => SettingService::getHomepageAbout($allianceName),
            'highlights' => SettingService::getHomepageHighlights(),
            'stats_intro' => SettingService::getHomepageStatsIntro(),
            'closing_text' => SettingService::getHomepageClosingText($allianceName),
            'hero_badge' => SettingService::getHomepageHeroBadge(),
            'cta_label' => SettingService::getHomepageCtaLabel(),
        ];

        $totalCities = $activeNationQuery ? (clone $activeNationQuery)->sum('num_cities') : null;
        $totalWarsWon = $activeNationQuery ? (clone $activeNationQuery)->sum('wars_won') : null;
        $totalWarsLost = $activeNationQuery ? (clone $activeNationQuery)->sum('wars_lost') : null;
        $totalPopulation = $activeNationQuery ? (clone $activeNationQuery)->sum('population') : null;

        $winRate = null;
        if ($totalWarsWon !== null && $totalWarsLost !== null) {
            $totalWars = $totalWarsWon + $totalWarsLost;
            $winRate = $totalWars > 0 ? round(($totalWarsWon / $totalWars) * 100, 1) : null;
        }

        $publicStats = [
            'members' => $activeNationQuery?->count(),
            'score' => $alliance?->score,
            'avgScore' => $activeNationQuery?->avg('score'),
            'rank' => $alliance?->rank,
            'color' => $alliance?->color,
            'flag' => $alliance?->flag,
            'discord_link' => $this->safePublicUrl($alliance?->discord_link),
            'forum_link' => $this->safePublicUrl($alliance?->forum_link),
            'wiki_link' => $this->safePublicUrl($alliance?->wiki_link),
            'totalCities' => $totalCities,
            'totalWarsWon' => $totalWarsWon,
            'winRate' => $winRate,
            'totalPopulation' => $totalPopulation,
        ];

        return view('home', [
            'appName' => $appName,
            'allianceName' => $allianceName,
            'alliance' => $alliance,
            'homeContent' => $homeContent,
            'publicStats' => $publicStats,
        ]);
    }

    private function safePublicUrl(?string $url): ?string
    {
        $url = is_string($url) ? trim($url) : '';

        if ($url === '' || preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            return null;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false ? $url : null;
    }
}
