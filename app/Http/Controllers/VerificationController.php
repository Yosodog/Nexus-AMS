<?php

namespace App\Http\Controllers;

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
}
