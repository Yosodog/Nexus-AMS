<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\PasswordResetNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PasswordResetLinkController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'nation_id' => ['required', 'integer', 'min:1'],
        ]);

        $rateLimiterKey = Str::lower('password-reset:' . $request->ip());

        if (RateLimiter::tooManyAttempts($rateLimiterKey, 5)) {
            $seconds = RateLimiter::availableIn($rateLimiterKey);

            throw ValidationException::withMessages([
                'nation_id' => trans('auth.throttle', [
                    'seconds' => $seconds,
                    'minutes' => (int) ceil($seconds / 60),
                ]),
            ]);
        }

        RateLimiter::hit($rateLimiterKey, 60);

        $nationId = (int) $validated['nation_id'];

        $user = User::where('nation_id', $nationId)->first();

        if ($user) {
            $token = Password::broker()->createToken($user);

            $user->notify(new PasswordResetNotification($token));
        }

        return back()->with('status', 'If the nation ID is registered, a password reset link has been sent to your in-game messages.');
    }
}
