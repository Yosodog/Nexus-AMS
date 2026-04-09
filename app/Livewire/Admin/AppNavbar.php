<?php

namespace App\Livewire\Admin;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class AppNavbar extends Component
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
        $nation = Auth::user()?->nation;

        $abbreviateNumber = function (?float $value): string {
            if ($value === null) {
                return '-';
            }
            $absValue = abs($value);
            foreach ([1_000_000_000_000 => 'T', 1_000_000_000 => 'B', 1_000_000 => 'M', 1_000 => 'K'] as $threshold => $suffix) {
                if ($absValue >= $threshold) {
                    return number_format($value / $threshold, 1).$suffix;
                }
            }

            return number_format($value, 0);
        };

        return view('livewire.admin.app-navbar', [
            'nation' => $nation,
            'abbreviateNumber' => $abbreviateNumber,
        ]);
    }
}
