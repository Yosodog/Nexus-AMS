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
        $navigation = $user ? app(AdminNavigationCatalog::class)->groups($user, $pendingCounts) : [];
        $groups = collect($navigation);
        $primaryNavigation = $groups
            ->where('area', 'primary')
            ->flatMap(fn (array $group): array => $group['items'])
            ->values()
            ->all();
        $departments = $groups->where('area', 'department')->values()->all();
        $systemNavigation = $groups->firstWhere('area', 'system');
        $quickAccessItems = $groups
            ->flatMap(fn (array $group): array => $group['items'])
            ->values()
            ->all();

        return view('livewire.admin.app-sidebar', [
            'primaryNavigation' => $primaryNavigation,
            'departments' => $departments,
            'systemNavigation' => $systemNavigation,
            'quickAccessItems' => $quickAccessItems,
            'pendingComplete' => $pendingRequests['complete'] ?? true,
            'pendingUnavailable' => $pendingRequests['unavailable'] ?? [],
        ]);
    }
}
