<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreApiTokenRequest;
use App\Services\AuditLogger;
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
    public function __construct(private readonly AuditLogger $auditLogger) {}

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
            'current_password' => ['nullable', 'required_with:password', 'current_password:web'],
        ]);

        $before = $user->only(['name', 'email']);

        $user->name = $request->input('name');
        $user->email = $request->input('email');

        $passwordChanged = false;
        if ($request->filled('password')) {
            $user->password = Hash::make($request->input('password'));
            $passwordChanged = true;
        }

        $user->save();

        if ($passwordChanged) {
            $user->tokens()->delete();
        }

        $changes = [];
        $after = $user->only(['name', 'email']);

        foreach ($after as $field => $value) {
            if ((string) ($before[$field] ?? null) !== (string) $value) {
                $changes[$field] = [
                    'from' => $before[$field] ?? null,
                    'to' => $value,
                ];
            }
        }

        $this->auditLogger->success(
            category: 'account',
            action: 'user_settings_updated',
            subject: $user,
            context: [
                'changes' => $changes,
                'data' => [
                    'password_changed' => $passwordChanged,
                ],
            ],
            message: 'User settings updated.'
        );

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

        $this->auditLogger->success(
            category: 'account',
            action: 'api_token_created',
            subject: $token->accessToken,
            context: [
                'data' => [
                    'token_id' => $token->accessToken->id,
                    'name' => $request->input('name'),
                    'expires_at' => $token->accessToken->expires_at,
                ],
            ],
            message: 'API token created.'
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

        $this->auditLogger->success(
            category: 'account',
            action: 'api_token_regenerated',
            subject: $newToken->accessToken,
            context: [
                'data' => [
                    'previous_token_id' => $tokenId,
                    'token_id' => $newToken->accessToken->id,
                    'name' => $request->input('name'),
                    'expires_at' => $newToken->accessToken->expires_at,
                ],
            ],
            message: 'API token regenerated.'
        );

        return redirect()
            ->route('user.settings')
            ->with('alert-message', 'API token regenerated. Copy it now because it will only be shown once.')
            ->with('alert-type', 'success')
            ->with('api-token', $newToken->plainTextToken);
    }

    public function revokeApiToken(Request $request, int $tokenId): RedirectResponse
    {
        $request->validate([
            'current_password' => ['required', 'string', 'current_password:web'],
        ]);

        $user = Auth::user();

        $token = $user->tokens()
            ->whereKey($tokenId)
            ->firstOrFail();

        $token->delete();

        $this->auditLogger->success(
            category: 'account',
            action: 'api_token_revoked',
            subject: $token,
            context: [
                'data' => [
                    'token_id' => $tokenId,
                    'name' => $token->name,
                    'expires_at' => $token->expires_at,
                ],
            ],
            message: 'API token revoked.'
        );

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
