<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Nation;
use App\Services\AllianceMembershipService;
use Illuminate\Http\JsonResponse;

class MembersController extends Controller
{
    public function index(AllianceMembershipService $membershipService): JsonResponse
    {
        $allianceIds = $membershipService->getAllianceIds();

        $members = Nation::query()
            ->with([
                'resources',
                'military',
                'user.discordAccounts',
            ])
            ->whereIn('alliance_id', $allianceIds)
            ->orderBy('id')
            ->get()
            ->map(function (Nation $nation): array {
                $activeDiscord = $nation->user?->discordAccounts
                    ?->whereNull('unlinked_at')
                    ->sortByDesc('linked_at')
                    ->first();

                $nation->unsetRelation('user');

                return [
                    'nation' => $nation,
                    'discord' => [
                        'nation_handle' => $nation->discord,
                        'nation_discord_id' => $nation->discord_id,
                        'account' => $activeDiscord
                            ? [
                                'discord_id' => $activeDiscord->discord_id,
                                'discord_username' => $activeDiscord->discord_username,
                                'linked_at' => $activeDiscord->linked_at,
                            ]
                            : null,
                    ],
                ];
            });

        return response()->json([
            'members' => $members,
        ]);
    }
}
