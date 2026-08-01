<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\NationVerification;
use Closure;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class VerificationController extends Controller
{
    /**
     * @param  Request  $request
     * @return mixed
     */
    public function verify(string $code)
    {
        if (Auth::user()->verification_code != $code) {
            return redirect()
                ->route('home')
                ->with([
                    'alert-message' => 'Invalid verification code.',
                    'alert-type' => 'error',
                ]);
        }

        Auth::user()->update([
            'verified_at' => now(),
            'verification_code' => null,
        ]);

        return redirect()
            ->route('home')
            ->with([
                'alert-message' => 'Your account has been verified! 🥳',
                'alert-type' => 'success',
            ]);
    }

    /**
     * @return Closure|Container|mixed|object|null
     */
    public function notVerified()
    {
        if (Auth::user()->isVerified()) {
            return redirect()
                ->route('home')
                ->with([
                    'alert-message' => 'Your account is already verified!',
                    'alert-type' => 'info',
                ]);
        }

        return view('auth.notverified');
    }

    /**
     * @return mixed
     */
    public function resendVerification()
    {
        $user = User::findOrFail(
            Auth::user()->id
        ); // I know this is weird but the notification needs the user model, not what Auth::user() returns.

        // Check if user is already verified
        if ($user->isVerified()) {
            return redirect()
                ->route('home')
                ->with([
                    'alert-message' => 'Your account is already verified!',
                    'alert-type' => 'info',
                ]);
        }

        $user->notify(new NationVerification($user));

        return redirect()
            ->route('not_verified')
            ->with([
                'alert-message' => 'A new verification message has been sent!',
                'alert-type' => 'success',
            ]);
    }
}
