<?php

namespace App\Livewire\Admin;

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
        $user = Auth::user();

        return view('livewire.admin.app-navbar', [
            'user' => $user,
            'nation' => $user?->nation,
        ]);
    }
}
