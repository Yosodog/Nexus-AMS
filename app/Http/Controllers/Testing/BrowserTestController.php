<?php

namespace App\Http\Controllers\Testing;

use App\Http\Controllers\Controller;
use App\Support\BrowserTestBootstrap;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BrowserTestController extends Controller
{
    public function login(Request $request, BrowserTestBootstrap $browserTestBootstrap, string $persona): RedirectResponse
    {
        abort_unless(app()->environment('testing'), 404);

        $users = $browserTestBootstrap->resetAndSeed();
        abort_unless(array_key_exists($persona, $users), 404);

        Auth::guard('web')->login($users[$persona]);
        $request->session()->regenerate();

        return redirect()->to($this->resolveRedirectTarget(
            persona: $persona,
            requestedTarget: $request->query('redirect')
        ));
    }

    private function resolveRedirectTarget(string $persona, mixed $requestedTarget): string
    {
        if (is_string($requestedTarget) && str_starts_with($requestedTarget, '/') && ! str_starts_with($requestedTarget, '//')) {
            return $requestedTarget;
        }

        return match ($persona) {
            'admin' => '/admin/users',
            default => '/user/settings',
        };
    }
}
