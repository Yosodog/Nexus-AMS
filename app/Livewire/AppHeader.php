<?php

namespace App\Livewire;

use App\Models\Grants;
use App\Services\AllianceMembershipService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class AppHeader extends Component
{
    public function logout()
    {
        Auth::logout();
        session()->invalidate();
        session()->regenerateToken();

        return redirect('/');
    }

    public function render()
    {
        $user = Auth::user();
        $pendingRequests = $pendingRequests ?? ['counts' => [], 'total' => 0];
        $pendingTotal = $pendingRequests['total'] ?? 0;

        $membershipService = app(AllianceMembershipService::class);
        $allianceId = data_get($user, 'nation.alliance_id');
        $showMemberNavigation = $user !== null && $membershipService->contains($allianceId);

        $enabledGrants = $showMemberNavigation
            ? Grants::query()->where('is_enabled', true)->orderBy('name')->get()
            : collect();

        $showPendingIndicator = $user && $pendingTotal > 0;

        return view('livewire.app-header', [
            'user' => $user,
            'showMemberNavigation' => $showMemberNavigation,
            'enabledGrants' => $enabledGrants,
            'pendingTotal' => $pendingTotal,
            'showPendingIndicator' => $showPendingIndicator,
        ]);
    }
}
