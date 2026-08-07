<?php

namespace App\Livewire\Admin;

use App\Models\User;
use App\Services\Admin\AdminNavigationCatalog;
use App\Services\PendingRequestsService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class AppSidebar extends Component
{
    public function render(): View
    {
        /** @var User|null $user */
        $user = Auth::user();
        $pendingRequests = $user
            ? app(PendingRequestsService::class)->getCountsForUser($user)
            : ['counts' => [], 'total' => 0];
        $pendingCounts = $pendingRequests['counts'] ?? [];

        return view('livewire.admin.app-sidebar', [
            'navigation' => $user ? app(AdminNavigationCatalog::class)->groups($user, $pendingCounts) : [],
            'pendingTotal' => $pendingRequests['total'] ?? 0,
        ]);
    }
}
