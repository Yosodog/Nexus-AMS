<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\Features;
use Laravel\Passkeys\Actions\GenerateRegistrationOptions;
use Laravel\Passkeys\Actions\GenerateVerificationOptions;
use Laravel\Passkeys\Events\PasskeyRegistered;
use Laravel\Passkeys\Events\PasskeyVerified;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Passkeys;
use Laravel\Passkeys\Support\Aaguids;
use Laravel\Passkeys\Support\WebAuthn;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;

class PasskeySecurityTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;

    public function test_passkey_configuration_is_origin_bound_and_management_routes_require_recent_authentication(): void
    {
        $this->assertTrue(Features::enabled(Features::passkeys()));
        $this->assertSame(parse_url((string) config('app.url'), PHP_URL_HOST), config('fortify.passkeys.relying_party_id'));
        $this->assertSame([config('app.url')], config('fortify.passkeys.allowed_origins'));
        $this->assertSame(config('app.key'), config('fortify.passkeys.user_handle_secret'));
        $this->assertSame(60000, config('fortify.passkeys.timeout'));
        $this->assertSame('passkeys', config('fortify.limiters.passkeys'));
        $this->assertSame('/user/dashboard', config('passkeys.redirect'));

        $storeRoute = Route::getRoutes()->getByName('passkey.store');
        $destroyRoute = Route::getRoutes()->getByName('passkey.destroy');
        $loginRoute = Route::getRoutes()->getByName('passkey.login');

        $this->assertNotNull($storeRoute);
        $this->assertNotNull($destroyRoute);
        $this->assertNotNull($loginRoute);
        $this->assertContains('auth:web', $storeRoute->middleware());
        $this->assertContains('password.confirm', $storeRoute->middleware());
        $this->assertContains('password.confirm', $destroyRoute->middleware());
        $this->assertContains('throttle:passkeys', $storeRoute->middleware());
        $this->assertContains('throttle:passkeys', $loginRoute->middleware());

        $this->assertInstanceOf(PasskeyUser::class, new User);
    }

    public function test_nonblank_dedicated_user_handle_secret_overrides_the_app_key_and_blank_values_do_not(): void
    {
        $originalValue = getenv('PASSKEY_USER_HANDLE_SECRET');

        try {
            putenv('PASSKEY_USER_HANDLE_SECRET=dedicated-passkey-handle-secret');
            $dedicatedConfig = require config_path('fortify.php');
            $this->assertSame(
                'dedicated-passkey-handle-secret',
                $dedicatedConfig['passkeys']['user_handle_secret'],
            );

            putenv('PASSKEY_USER_HANDLE_SECRET=');
            $fallbackConfig = require config_path('fortify.php');
            $this->assertSame(config('app.key'), $fallbackConfig['passkeys']['user_handle_secret']);
        } finally {
            $originalValue === false
                ? putenv('PASSKEY_USER_HANDLE_SECRET')
                : putenv('PASSKEY_USER_HANDLE_SECRET='.$originalValue);
        }
    }

    public function test_passkey_login_deliberately_uses_required_user_verification_as_phishing_resistant_mfa(): void
    {
        $user = User::factory()->create();
        $registrationOptions = WebAuthn::toBrowserArray(
            app(GenerateRegistrationOptions::class)($user),
        );
        $verificationOptions = WebAuthn::toBrowserArray(
            app(GenerateVerificationOptions::class)(),
        );

        $this->assertSame(
            'required',
            data_get($registrationOptions, 'authenticatorSelection.userVerification'),
        );
        $this->assertSame(
            'required',
            data_get($registrationOptions, 'authenticatorSelection.residentKey'),
        );
        $this->assertSame('required', data_get($verificationOptions, 'userVerification'));
        $this->assertNotNull(Route::getRoutes()->getByName('passkey.login'));
        $this->assertNotNull(Route::getRoutes()->getByName('two-factor.login'));
        $this->assertNotNull(Route::getRoutes()->getByName('two-factor.recovery-codes'));
        $this->assertNotNull(Route::getRoutes()->getByName('password.request'));
        $this->assertTrue(Features::enabled(Features::twoFactorAuthentication()));
    }

    public function test_settings_show_multiple_named_passkeys_safe_authenticator_labels_and_exact_times(): void
    {
        $user = $this->createVerifiedUser();
        $this->attachDiscordAccount($user);
        $knownAaguid = array_key_first(Aaguids::all());
        $knownAuthenticator = Aaguids::labelFor($knownAaguid);
        $first = $this->createPasskey($user, [
            'name' => 'Personal MacBook',
            'credential' => [
                'aaguid' => $knownAaguid,
                'publicKeyCredentialSource' => 'must-not-render',
            ],
        ])->forceFill([
            'created_at' => now()->subMonth(),
            'last_used_at' => now()->subHour(),
        ]);
        $first->save();
        $second = $this->createPasskey($user, ['name' => 'YubiKey 5 NFC']);

        $response = $this
            ->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => now()->unix()])
            ->get(route('user.settings'));

        $response
            ->assertOk()
            ->assertSee('2 registered')
            ->assertSee('Personal MacBook')
            ->assertSee('YubiKey 5 NFC')
            ->assertSee((string) $knownAuthenticator)
            ->assertSee('Passkey created')
            ->assertSee('Passkey last used')
            ->assertSee('Revoke')
            ->assertSee(route('passkey.destroy', $first), false)
            ->assertSee(route('passkey.destroy', $second), false)
            ->assertDontSee('must-not-render')
            ->assertDontSee($first->credential_id);
    }

    public function test_passkey_deletion_enforces_recent_authentication_and_ownership(): void
    {
        $owner = $this->createVerifiedUser();
        $otherUser = $this->createVerifiedUser();
        $passkey = $this->createPasskey($owner);

        $this
            ->actingAs($otherUser)
            ->withSession(['auth.password_confirmed_at' => now()->unix()])
            ->delete(route('passkey.destroy', $passkey))
            ->assertForbidden();

        $this->assertDatabaseHas('passkeys', ['id' => $passkey->id]);

        $this
            ->actingAs($owner)
            ->withSession(['auth.password_confirmed_at' => 0])
            ->delete(route('passkey.destroy', $passkey))
            ->assertRedirect(route('password.confirm'));

        $this->assertDatabaseHas('passkeys', ['id' => $passkey->id]);

        $this
            ->actingAs($owner)
            ->withSession(['auth.password_confirmed_at' => now()->unix()])
            ->from(route('user.settings').'#passkeys')
            ->delete(route('passkey.destroy', $passkey))
            ->assertRedirect(route('user.settings').'#passkeys')
            ->assertSessionHas('status', 'passkey-deleted');

        $this->assertDatabaseMissing('passkeys', ['id' => $passkey->id]);
        $this->assertDatabaseHas('audit_logs', [
            'category' => 'authentication',
            'action' => 'passkey_deleted',
            'outcome' => 'success',
            'actor_id' => $owner->id,
        ]);
    }

    public function test_disabled_accounts_are_denied_after_verification_without_logging_credential_material(): void
    {
        $enabledUser = User::factory()->create(['disabled' => false]);
        $disabledUser = User::factory()->create(['disabled' => true]);
        $enabledPasskey = $this->createPasskey($enabledUser);
        $disabledPasskey = $this->createPasskey($disabledUser, [
            'credential' => [
                'aaguid' => Aaguids::unknown(),
                'publicKeyCredentialSource' => 'sensitive-credential-material',
            ],
        ]);
        $request = Request::create('/passkeys/login', 'POST');

        $this->assertTrue(Passkeys::allowsLogin($request, $enabledPasskey));
        $this->assertFalse(Passkeys::allowsLogin($request, $disabledPasskey));

        $audit = AuditLog::query()
            ->where('action', 'passkey_login')
            ->where('outcome', 'denied')
            ->firstOrFail();
        $encodedContext = json_encode($audit->context, JSON_THROW_ON_ERROR);

        $this->assertStringContainsString('account_disabled', $encodedContext);
        $this->assertStringNotContainsString('sensitive-credential-material', $encodedContext);
        $this->assertStringNotContainsString('credential_id', $encodedContext);
    }

    public function test_passkey_verification_and_login_events_are_audited_with_sanitized_metadata(): void
    {
        $user = User::factory()->create();
        $passkey = $this->createPasskey($user, [
            'name' => "Work key\nwith control text",
            'credential' => [
                'aaguid' => Aaguids::unknown(),
                'publicKeyCredentialSource' => 'never-audit-this',
            ],
        ]);
        $route = new RoutingRoute(['POST'], '/passkeys/login', []);
        $route->name('passkey.login');
        $request = Request::create('/passkeys/login', 'POST');
        $request->setRouteResolver(static fn (): RoutingRoute => $route);
        $this->app->instance('request', $request);

        event(new PasskeyRegistered($user, $passkey));
        event(new PasskeyVerified($user, $passkey));
        event(new Login('web', $user, false));

        $registered = AuditLog::query()->where('action', 'passkey_registered')->firstOrFail();
        $verified = AuditLog::query()->where('action', 'passkey_verified')->firstOrFail();
        $login = AuditLog::query()->where('action', 'passkey_login')->where('outcome', 'success')->firstOrFail();
        $encodedContext = json_encode([$registered->context, $verified->context, $login->context], JSON_THROW_ON_ERROR);

        $this->assertSame($passkey->id, $registered->context['passkey_id']);
        $this->assertSame('login', $verified->context['purpose']);
        $this->assertSame('web', $login->context['guard']);
        $this->assertStringNotContainsString('Work key', $encodedContext);
        $this->assertStringNotContainsString('control text', $encodedContext);
        $this->assertStringNotContainsString('never-audit-this', $encodedContext);
        $this->assertStringNotContainsString('publicKeyCredentialSource', $encodedContext);
    }

    public function test_duplicate_credential_ids_are_rejected_by_the_database_boundary(): void
    {
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        $credentialId = 'duplicate-credential-id';

        $this->createPasskey($firstUser, ['credential_id' => $credentialId]);

        $this->expectException(QueryException::class);

        $this->createPasskey($secondUser, ['credential_id' => $credentialId]);
    }

    public function test_password_passkey_mfa_and_recovery_flows_coexist(): void
    {
        $user = User::factory()->create();
        $this->createPasskey($user);

        $this
            ->actingAs($user)
            ->get(route('password.confirm', ['return_to' => 'passkeys']))
            ->assertOk()
            ->assertSee('Confirm password and continue')
            ->assertSee('Confirm with a passkey')
            ->assertSessionHas('url.intended', route('user.settings').'#passkeys');

        $this->post(route('logout'));

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Sign in to member app')
            ->assertSee('Sign in with a passkey')
            ->assertSee('name="csrf-token"', false)
            ->assertSee(route('password.request'), false);

        $this->assertTrue(Features::enabled(Features::resetPasswords()));
        $this->assertTrue(Features::enabled(Features::twoFactorAuthentication()));
        $this->assertTrue(Features::enabled(Features::passkeys()));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createPasskey(User $user, array $overrides = []): Passkey
    {
        return $user->passkeys()->create([
            'name' => 'Test passkey',
            'credential_id' => (string) Str::uuid(),
            'credential' => ['aaguid' => Aaguids::unknown()],
            ...$overrides,
        ]);
    }
}
