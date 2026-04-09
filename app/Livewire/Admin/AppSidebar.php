<?php

namespace App\Livewire\Admin;

use App\Services\PendingRequestsService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class AppSidebar extends Component
{
    public function render()
    {
        $user = Auth::user();

        $pendingRequests = ['counts' => [], 'total' => 0];
        if ($user) {
            $pendingRequests = app(PendingRequestsService::class)->getCountsForUser($user);
        }

        $pendingCounts = $pendingRequests['counts'] ?? [];

        return view('livewire.admin.app-sidebar', [
            'pendingCounts' => $pendingCounts,
            'pendingTotal' => $pendingRequests['total'] ?? 0,
            'grantsPending' => ($pendingCounts['city_grants'] ?? 0) + ($pendingCounts['grants'] ?? 0),
            'financePending' => $pendingCounts['withdrawals'] ?? 0,
            'warsPending' => ($pendingCounts['war_aid'] ?? 0) + ($pendingCounts['rebuilding'] ?? 0),
        ]);
    }
}
