<?php

namespace App\Http\Controllers;

use App\Services\DiscordAccountService;
use App\Services\NationDashboardService;
use App\Services\SettingService;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    /**
     * @return Factory|View|Application|object
     */
    public function settings()
    {
        $user = Auth::user();
        $discordAccount = DiscordAccountService::getActiveAccount($user);

        return view('user.settings', [
            'user' => $user,
            'discordAccount' => $discordAccount,
            'discordVerificationToken' => $discordAccount ? null : DiscordAccountService::getOrCreateVerificationToken($user),
            'discordVerificationRequired' => SettingService::isDiscordVerificationRequired(),
        ]);
    }

    /**
     * @return RedirectResponse
     */
    public function updateSettings(Request $request)
    {
        $user = Auth::user();

        $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:users,name,'.$user->id], // Ensure unique name
            'email' => ['required', 'email', 'max:255', 'unique:users,email,'.$user->id], // Ensure unique email
            'password' => ['nullable', Password::defaults(), 'confirmed'],
        ]);

        $user->name = $request->input('name');
        $user->email = $request->input('email');

        if ($request->filled('password')) {
            $user->password = Hash::make($request->input('password'));
        }

        $user->save();

        return redirect()->route('user.settings')->with('alert-message', 'Setting updated successfully!')->with(
            'alert-type',
            'success'
        );
    }

    public function dashboard(NationDashboardService $dashboardService): View
    {
        $nation = Auth::user()->nation;

        return view('user.dashboard', array_merge(
            ['nation' => $nation],
            $dashboardService->getDashboardData($nation),
            [
                'mmrScore' => 0,
                'grantTotal' => 0,
                'loanTotal' => 0,
            ]
        ));
    }
}
