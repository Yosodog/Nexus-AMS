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
        ];

        $publicStats = [
            'members' => $activeNationQuery?->count(),
            'score' => $alliance?->score,
            'avgScore' => $activeNationQuery?->avg('score'),
            'rank' => $alliance?->rank,
            'color' => $alliance?->color,
            'flag' => $alliance?->flag,
            'discord_link' => $alliance?->discord_link,
            'forum_link' => $alliance?->forum_link,
            'wiki_link' => $alliance?->wiki_link,
        ];

        return view('home', [
            'appName' => $appName,
            'allianceName' => $allianceName,
            'alliance' => $alliance,
            'homeContent' => $homeContent,
            'publicStats' => $publicStats,
        ]);
    }
}
