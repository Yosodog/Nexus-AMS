<?php

namespace App\Http\Controllers;

use App\Services\DiscordAccountService;
use App\Services\SettingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class DiscordVerificationController extends Controller
{
    /**
     * Display the Discord verification page with the user's current status.
     */
    public function show(): View
    {
        $user = Auth::user();
        $discordAccount = DiscordAccountService::getActiveAccount($user);
        $verificationToken = $discordAccount ? null : DiscordAccountService::getOrCreateVerificationToken($user);

        return view('auth.verify-discord', [
            'discordAccount' => $discordAccount,
            'verificationToken' => $verificationToken,
            'discordRequired' => SettingService::isDiscordVerificationRequired(),
        ]);
    }

    /**
     * Regenerate the Discord verification token for the authenticated user.
     */
    public function regenerateToken(Request $request): RedirectResponse
    {
        DiscordAccountService::regenerateVerificationToken($request->user());

        return back()->with([
            'alert-message' => 'Discord verification token regenerated.',
            'alert-type' => 'success',
        ]);
    }

    /**
     * Unlink the active Discord account for the authenticated user.
     */
    public function unlink(Request $request): RedirectResponse
    {
        $discordAccount = DiscordAccountService::unlinkUser($request->user());

        $message = $discordAccount
            ? 'Discord account unlinked.'
            : 'No active Discord account was found to unlink.';

        return back()->with([
            'alert-message' => $message,
            'alert-type' => $discordAccount ? 'success' : 'info',
        ]);
    }
}
