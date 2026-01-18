<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreApiTokenRequest;
use App\Services\DiscordAccountService;
use App\Services\NationDashboardService;
use App\Services\SettingService;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
        $apiTokens = $user->tokens()
            ->latest('created_at')
            ->get();

        return view('user.settings', [
            'user' => $user,
            'discordAccount' => $discordAccount,
            'apiTokens' => $apiTokens,
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

    public function storeApiToken(StoreApiTokenRequest $request): RedirectResponse
    {
        $user = Auth::user();

        $token = $user->createToken(
            $request->input('name'),
            ['*'],
            $this->prepareTokenExpiration($request)
        );

        return redirect()
            ->route('user.settings')
            ->with('alert-message', 'API token created. Copy it now because it will only be shown once.')
            ->with('alert-type', 'success')
            ->with('api-token', $token->plainTextToken);
    }

    public function regenerateApiToken(StoreApiTokenRequest $request, int $tokenId): RedirectResponse
    {
        $user = Auth::user();

        $token = $user->tokens()
            ->whereKey($tokenId)
            ->firstOrFail();

        $token->delete();

        $newToken = $user->createToken(
            $request->input('name'),
            ['*'],
            $this->prepareTokenExpiration($request)
        );

        return redirect()
            ->route('user.settings')
            ->with('alert-message', 'API token regenerated. Copy it now because it will only be shown once.')
            ->with('alert-type', 'success')
            ->with('api-token', $newToken->plainTextToken);
    }

    public function revokeApiToken(int $tokenId): RedirectResponse
    {
        $user = Auth::user();

        $token = $user->tokens()
            ->whereKey($tokenId)
            ->firstOrFail();

        $token->delete();

        return redirect()
            ->route('user.settings')
            ->with('alert-message', 'API token revoked.')
            ->with('alert-type', 'success');
    }

    public function dashboard(NationDashboardService $dashboardService): View
    {
        $nation = Auth::user()->nation;

        return view('user.dashboard', array_merge(
            ['nation' => $nation],
            $dashboardService->getDashboardData($nation),
            [
                'grantTotal' => 0,
                'loanTotal' => 0,
            ]
        ));
    }

    private function prepareTokenExpiration(StoreApiTokenRequest $request): ?Carbon
    {
        if (! $request->filled('expires_at')) {
            return null;
        }

        return Carbon::parse($request->input('expires_at'))->endOfDay();
    }
}
