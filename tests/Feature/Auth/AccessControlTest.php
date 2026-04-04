<?php

namespace Tests\Feature\Auth;

use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\DiscordVerifiedMiddleware;
use App\Http\Middleware\EnsureMfaConfigured;
use App\Http\Middleware\EnsureUserIsVerified;
use App\Models\Setting;
use App\Services\TrustedDeviceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\BuildsTestUsers;
use Tests\FeatureTestCase;

class AccessControlTest extends FeatureTestCase
{
    use BuildsTestUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureIsolatedTestDatabase();
        Schema::dropAllTables();
        $this->createTables();

        Route::middleware(['web', 'auth', EnsureUserIsVerified::class, DiscordVerifiedMiddleware::class, EnsureMfaConfigured::class])
            ->get('/_testing/user-area', fn () => response('ok'))
            ->name('testing.user-area');

        Route::middleware(['api', 'auth:sanctum', EnsureUserIsVerified::class, DiscordVerifiedMiddleware::class, AdminMiddleware::class, 'can:view-members'])
            ->get('/api/_testing/members', fn () => response()->json(['ok' => true]));
    }

    public function test_unverified_users_are_redirected_to_the_not_verified_page(): void
    {
        $user = $this->createVerifiedUser();
        $user->forceFill(['verified_at' => null])->save();

        $this->actingAs($user)
            ->get('/_testing/user-area')
            ->assertRedirect('/notverified');
    }

    public function test_verified_users_without_discord_are_redirected_when_discord_verification_is_required(): void
    {
        $user = $this->createVerifiedUser();
        Setting::query()->create(['key' => 'require_discord_verification', 'value' => '1']);

        $this->actingAs($user)
            ->get('/_testing/user-area')
            ->assertRedirect(route('discord.verify.show'));
    }

    public function test_verified_users_with_discord_can_access_protected_routes(): void
    {
        $user = $this->createVerifiedUser();
        Setting::query()->create(['key' => 'require_discord_verification', 'value' => '1']);
        $this->attachDiscordAccount($user);

        $this->actingAs($user)
            ->get('/_testing/user-area')
            ->assertOk();
    }

    public function test_admins_are_redirected_to_mfa_setup_when_required(): void
    {
        $admin = $this->createVerifiedAdmin();
        $this->attachDiscordAccount($admin);
        Setting::query()->create(['key' => 'require_mfa_admins', 'value' => '1']);

        $this->actingAs($admin)
            ->get('/_testing/user-area')
            ->assertRedirect(route('user.settings'));
    }

    public function test_admins_with_confirmed_two_factor_can_access_routes_when_mfa_is_required(): void
    {
        $admin = $this->enableTwoFactor($this->createVerifiedAdmin());
        $this->attachDiscordAccount($admin);
        Setting::query()->create(['key' => 'require_mfa_admins', 'value' => '1']);

        $this->actingAs($admin)
            ->get('/_testing/user-area')
            ->assertOk();
    }

    public function test_permission_gated_api_routes_require_the_assigned_permission(): void
    {
        Setting::query()->create(['key' => 'require_discord_verification', 'value' => '1']);

        $admin = $this->createVerifiedAdmin();
        $this->attachDiscordAccount($admin);
        $this->actingAsSanctum($admin);

        $this->getJson('/api/_testing/members')->assertForbidden();

        $authorizedAdmin = $this->createVerifiedAdmin(['nation_id' => 900002]);
        $this->attachDiscordAccount($authorizedAdmin, ['discord_id' => '987654321']);
        $authorizedAdmin = $this->grantPermissions($authorizedAdmin, ['view-members']);
        $this->actingAsSanctum($authorizedAdmin);

        $this->getJson('/api/_testing/members')->assertOk();
    }

    public function test_trusted_device_cookies_use_the_session_domain_and_secure_flag_for_https_requests(): void
    {
        $user = $this->createVerifiedUser();
        $request = Request::create('https://nexus-ams.test/login', 'POST', server: [
            'HTTP_USER_AGENT' => 'Codex Test',
            'HTTPS' => 'on',
        ]);

        config()->set('session.domain', '.nexus-ams.test');

        $cookie = app(TrustedDeviceService::class)->issueTrustedDeviceCookie($request, $user);

        $this->assertSame('.nexus-ams.test', $cookie->getDomain());
        $this->assertTrue($cookie->isSecure());
        $this->assertSame(TrustedDeviceService::COOKIE_NAME, $cookie->getName());
    }

    private function createTables(): void
    {
        Schema::create('users', function ($table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->unsignedInteger('nation_id');
            $table->boolean('is_admin')->default(false);
            $table->boolean('disabled')->default(false);
            $table->timestamp('last_active_at')->nullable();
            $table->string('verification_code')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->string('discord_verification_token')->nullable()->unique();
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('roles', function ($table): void {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('protected')->default(false);
            $table->timestamps();
        });

        Schema::create('role_user', function ($table): void {
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('role_id');
            $table->primary(['user_id', 'role_id']);
        });

        Schema::create('role_permissions', function ($table): void {
            $table->unsignedBigInteger('role_id');
            $table->string('permission');
            $table->primary(['role_id', 'permission']);
        });

        Schema::create('settings', function ($table): void {
            $table->id();
            $table->string('key')->unique();
            $table->string('value');
            $table->timestamps();
        });

        Schema::create('discord_accounts', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('discord_id');
            $table->string('discord_username');
            $table->timestamp('linked_at');
            $table->timestamp('unlinked_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('trusted_devices', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('token_hash');
            $table->string('user_agent_hash');
            $table->string('user_agent')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }
}
