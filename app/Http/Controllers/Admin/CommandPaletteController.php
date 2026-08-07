<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SearchCommandPaletteRequest;
use App\Models\Nation;
use App\Services\AllianceMembershipService;
use Illuminate\Http\JsonResponse;

final class CommandPaletteController extends Controller
{
    public function search(
        SearchCommandPaletteRequest $request,
        AllianceMembershipService $membershipService,
    ): JsonResponse {
        $query = trim($request->validated('query'));
        $escapedQuery = addcslashes($query, '%_\\');

        $members = Nation::query()
            ->select(['id', 'nation_name', 'leader_name'])
            ->whereIn('alliance_id', $membershipService->getAllianceIds())
            ->where('alliance_position', '!=', 'APPLICANT')
            ->where(function ($memberQuery) use ($query, $escapedQuery): void {
                if (ctype_digit($query)) {
                    $memberQuery->orWhere('id', (int) $query);
                }

                $memberQuery
                    ->orWhere('nation_name', 'like', "%{$escapedQuery}%")
                    ->orWhere('leader_name', 'like', "%{$escapedQuery}%");
            })
            ->orderByRaw('CASE WHEN nation_name = ? THEN 0 WHEN leader_name = ? THEN 1 ELSE 2 END', [$query, $query])
            ->orderBy('nation_name')
            ->limit(8)
            ->get()
            ->map(fn (Nation $nation): array => [
                'id' => 'member:'.$nation->id,
                'type' => 'Member',
                'label' => $nation->nation_name,
                'description' => $nation->leader_name.' · Nation #'.$nation->id,
                'url' => route('admin.members.show', $nation),
            ])
            ->values();

        return response()->json(['results' => $members]);
    }
}
