<?php

namespace App\Livewire\Admin;

use App\Models\User;
use App\Services\Admin\AdminNavigationCatalog;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class AppNavbar extends Component
{
    public function logout(): void
    {
        Auth::logout();
        session()->invalidate();
        session()->regenerateToken();

        $this->redirectRoute('home');
    }

    public function render(): View
    {
        /** @var User|null $user */
        $user = Auth::user();

        return view('livewire.admin.app-navbar', [
            'user' => $user,
            'nation' => $user?->nation,
            'commands' => $user ? app(AdminNavigationCatalog::class)->commands($user) : [],
            'entitySearchUrl' => $user?->can('view-members')
                ? route('admin.command-palette.search')
                : null,
        ]);
    }
}
