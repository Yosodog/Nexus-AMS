<?php

namespace App\Http\Controllers;

use App\Notifications\NationVerification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class VerificationController extends Controller
{

    /**
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $code
     *
     * @return mixed
     */
    public function verify(string $code)
    {
        if (Auth::user()->verification_code != $code) {
            return redirect()
                ->route("home")
                ->with([
                    'alert-message' => 'Invalid verification code.',
                    "alert-type" => 'error',
                ]);
        }

        Auth::user()->update([
            'verified_at' => now(),
            'verification_code' => null
        ]);

        return redirect()
            ->route("home")
            ->with([
                'alert-message' => 'Your account has been verified! 🥳',
                "alert-type" => 'success',
            ]);
    }

    /**
     * @return \Closure|\Illuminate\Container\Container|mixed|object|null
     */
    public function notVerified()
    {
        return view("auth.notverified");
    }

    /**
     * @return mixed
     * @throws \Exception
     */
    public function resendVerification()
    {
        if (session()->has('last_verification_attempt')) {
            $secondsSinceLastAttempt = abs(now()->diffInSeconds(session('last_verification_attempt')));

            echo session()->has('last_verification_attempt');
            echo "hi";

            if ($secondsSinceLastAttempt < 60) { // Allow every 60 seconds
                return redirect()->route("not_verified")->with([
                    'alert-message' => 'Please wait before requesting another verification message.',
                    'alert-type' => 'warning',
                ]);
            }
        }

        $user = Auth::user();

        // Check if user is already verified
        if ($user->isVerified()) {
            return redirect()
                ->route("/")
                ->with([
                    'alert-message' => 'Your account is already verified!',
                    'alert-type' => 'info',
                ]);
        }

        // Generate a new verification code
        $verification_code = strtoupper(bin2hex(random_bytes(16)));

        // Update user record
        $user->update(['verification_code' => $verification_code]);

        // Send the new verification message
        $user->notify(new NationVerification($user));

        // Store the last attempt timestamp
        session(['last_verification_attempt' => now()]);

        return redirect()
            ->route("not_verified")
            ->with([
                'alert-message' => 'A new verification message has been sent!',
                'alert-type' => 'success',
            ]);
    }
}
