<?php

namespace Tests\Feature\Admin;

use App\Http\Controllers\Admin\DataSyncSettingsController;
use App\Http\Controllers\Admin\DiscordSettingsController;
use App\Http\Controllers\Admin\FinancePolicySettingsController;
use App\Http\Controllers\Admin\PendingRequestRecoveryController;
use App\Http\Controllers\Admin\PublicSiteSettingsController;
use App\Http\Controllers\Admin\SecurityRetentionSettingsController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\SystemHealthController;
use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\BlockWhenPWDown;
use App\Http\Middleware\DiscordVerifiedMiddleware;
use App\Http\Middleware\EnsureMfaConfigured;
use App\Http\Middleware\EnsureUserIsVerified;
use App\Http\Requests\Admin\CancelDataSyncRequest;
use App\Http\Requests\Admin\ReleaseStalePendingRequestsRequest;
use App\Http\Requests\Admin\RunDataSyncRequest;
use App\Http\Requests\Admin\StoreFaviconRequest;
use App\Http\Requests\Admin\UpdateAuditRetentionSettingsRequest;
use App\Http\Requests\Admin\UpdateAutoWithdrawSettingsRequest;
use App\Http\Requests\Admin\UpdateBackupSettingsRequest;
use App\Http\Requests\Admin\UpdateDiscordCityTierSettingsRequest;
use App\Http\Requests\Admin\UpdateDiscordDepartureRequest;
use App\Http\Requests\Admin\UpdateDiscordPrivateNotificationsRequest;
use App\Http\Requests\Admin\UpdateDiscordVerificationRequest;
use App\Http\Requests\Admin\UpdateGrantApprovalSettingsRequest;
use App\Http\Requests\Admin\UpdateHomepageSettingsRequest;
use App\Http\Requests\Admin\UpdateLoanPaymentSettingsRequest;
use App\Http\Requests\Admin\UpdateSeoSettingsRequest;
use App\Http\Requests\Admin\UpdateUserInactivityAutoDisableRequest;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;

class SettingsDomainControllerTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;

    public function test_legacy_settings_routes_preserve_names_uris_methods_and_middleware(): void
    {
        $contracts = [
            'admin.settings' => ['GET', 'admin/settings', SettingsController::class.'@index'],
            'admin.settings.sync.run' => ['POST', 'admin/settings/sync/nations', DataSyncSettingsController::class.'@runNation'],
            'admin.settings.sync.alliances' => ['POST', 'admin/settings/sync/alliances', DataSyncSettingsController::class.'@runAlliance'],
            'admin.settings.sync.wars' => ['POST', 'admin/settings/sync/wars', DataSyncSettingsController::class.'@runWar'],
            'admin.settings.sync.cancel' => ['POST', 'admin/settings/sync/cancel', DataSyncSettingsController::class.'@cancel'],
            'admin.settings.discord' => ['POST', 'admin/settings/discord', DiscordSettingsController::class.'@updateVerification'],
            'admin.settings.discord.departure' => ['POST', 'admin/settings/discord/departure', DiscordSettingsController::class.'@updateDeparture'],
            'admin.settings.discord.private-notifications' => ['POST', 'admin/settings/discord/private-notifications', DiscordSettingsController::class.'@updatePrivateNotifications'],
            'admin.settings.discord.city-tiers' => ['POST', 'admin/settings/discord/city-tiers', DiscordSettingsController::class.'@updateCityTiers'],
            'admin.settings.homepage' => ['POST', 'admin/settings/homepage', PublicSiteSettingsController::class.'@updateHomepage'],
            'admin.settings.seo' => ['POST', 'admin/settings/seo', PublicSiteSettingsController::class.'@updateSeo'],
            'admin.settings.favicon' => ['POST', 'admin/settings/favicon', PublicSiteSettingsController::class.'@updateFavicon'],
            'admin.settings.auto-withdraw' => ['POST', 'admin/settings/auto-withdraw', FinancePolicySettingsController::class.'@updateAutoWithdraw'],
            'admin.settings.backups' => ['POST', 'admin/settings/backups', SecurityRetentionSettingsController::class.'@updateBackups'],
            'admin.settings.loan-payments' => ['POST', 'admin/settings/loan-payments', FinancePolicySettingsController::class.'@updateLoanPayments'],
            'admin.settings.grants.approvals' => ['POST', 'admin/settings/grants/approvals', FinancePolicySettingsController::class.'@updateGrantApprovals'],
            'admin.settings.audit-retention' => ['POST', 'admin/settings/audit-retention', SecurityRetentionSettingsController::class.'@updateAuditRetention'],
            'admin.settings.account-inactivity-auto-disable' => ['POST', 'admin/settings/account-inactivity-auto-disable', SecurityRetentionSettingsController::class.'@updateUserInactivity'],
            'admin.settings.pending-requests.release-stale' => ['POST', 'admin/settings/pending-requests/release-stale', PendingRequestRecoveryController::class.'@store'],
        ];
        $commonMiddleware = [
            'web',
            'auth',
            EnsureUserIsVerified::class,
            DiscordVerifiedMiddleware::class,
            EnsureMfaConfigured::class,
            AdminMiddleware::class,
        ];
        $pwGuardedRoutes = [
            'admin.settings.sync.run',
            'admin.settings.sync.alliances',
            'admin.settings.sync.wars',
        ];

        foreach ($contracts as $name => [$method, $uri, $action]) {
            $route = Route::getRoutes()->getByName($name);
            $expectedMiddleware = $commonMiddleware;

            if (in_array($name, $pwGuardedRoutes, true)) {
                $expectedMiddleware[] = BlockWhenPWDown::class;
            }

            $this->assertNotNull($route, "{$name} should remain registered.");
            $this->assertSame($uri, $route->uri(), "{$name} changed URI.");
            $this->assertContains($method, $route->methods(), "{$name} changed HTTP method.");
            $this->assertSame($action, $route->getActionName(), "{$name} should use its focused controller.");
            $this->assertSame($expectedMiddleware, $route->gatherMiddleware(), "{$name} changed middleware ordering.");
        }

        $focusedRoutes = [
            'admin.settings.public-site' => ['admin/settings/public-site', PublicSiteSettingsController::class.'@index'],
            'admin.settings.discord.index' => ['admin/settings/discord', DiscordSettingsController::class.'@index'],
            'admin.settings.finance-policy' => ['admin/settings/finance-policy', FinancePolicySettingsController::class.'@index'],
            'admin.settings.security-retention' => ['admin/settings/security-retention', SecurityRetentionSettingsController::class.'@index'],
            'admin.settings.data-sync' => ['admin/settings/data-sync', DataSyncSettingsController::class.'@index'],
            'admin.settings.recovery' => ['admin/settings/recovery', PendingRequestRecoveryController::class.'@index'],
            'admin.settings.system-health' => ['admin/settings/system-health', SystemHealthController::class],
        ];

        foreach ($focusedRoutes as $name => [$uri, $action]) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route);
            $this->assertSame($uri, $route->uri());
            $this->assertContains('GET', $route->methods());
            $this->assertSame($action, $route->getActionName());
            $this->assertSame($commonMiddleware, $route->gatherMiddleware());
        }
    }

    public function test_every_legacy_settings_post_route_uses_a_dedicated_form_request(): void
    {
        $requests = [
            'admin.settings.sync.run' => RunDataSyncRequest::class,
            'admin.settings.sync.alliances' => RunDataSyncRequest::class,
            'admin.settings.sync.wars' => RunDataSyncRequest::class,
            'admin.settings.sync.cancel' => CancelDataSyncRequest::class,
            'admin.settings.discord' => UpdateDiscordVerificationRequest::class,
            'admin.settings.discord.departure' => UpdateDiscordDepartureRequest::class,
            'admin.settings.discord.private-notifications' => UpdateDiscordPrivateNotificationsRequest::class,
            'admin.settings.discord.city-tiers' => UpdateDiscordCityTierSettingsRequest::class,
            'admin.settings.homepage' => UpdateHomepageSettingsRequest::class,
            'admin.settings.seo' => UpdateSeoSettingsRequest::class,
            'admin.settings.favicon' => StoreFaviconRequest::class,
            'admin.settings.auto-withdraw' => UpdateAutoWithdrawSettingsRequest::class,
            'admin.settings.backups' => UpdateBackupSettingsRequest::class,
            'admin.settings.loan-payments' => UpdateLoanPaymentSettingsRequest::class,
            'admin.settings.grants.approvals' => UpdateGrantApprovalSettingsRequest::class,
            'admin.settings.audit-retention' => UpdateAuditRetentionSettingsRequest::class,
            'admin.settings.account-inactivity-auto-disable' => UpdateUserInactivityAutoDisableRequest::class,
            'admin.settings.pending-requests.release-stale' => ReleaseStalePendingRequestsRequest::class,
        ];

        foreach ($requests as $routeName => $requestClass) {
            [$controller, $method] = explode('@', Route::getRoutes()->getByName($routeName)->getActionName(), 2);
            $parameterTypes = collect((new ReflectionMethod($controller, $method))->getParameters())
                ->map(fn ($parameter) => $parameter->getType())
                ->filter(fn ($type): bool => $type instanceof ReflectionNamedType)
                ->map(fn (ReflectionNamedType $type): string => $type->getName())
                ->filter(fn (string $type): bool => is_a($type, FormRequest::class, true));

            $this->assertSame([$requestClass], $parameterTypes->values()->all(), "{$routeName} must use its domain Form Request.");
        }
    }

    public function test_domain_pages_are_focused_and_permission_filtered(): void
    {
        $diagnosticAdmin = $this->createAdmin(['view-diagnostic-info']);

        $this->actingAs($diagnosticAdmin)
            ->get(route('admin.settings.public-site'))
            ->assertOk()
            ->assertSee('Homepage Messaging')
            ->assertDontSee('Verification requirement')
            ->assertDontSee('Audit log retention');

        $this->actingAs($diagnosticAdmin)
            ->get(route('admin.settings.discord.index'))
            ->assertOk()
            ->assertSee('Discord verification')
            ->assertDontSee('Homepage Messaging');

        $this->actingAs($diagnosticAdmin)
            ->get(route('admin.settings.data-sync'))
            ->assertOk()
            ->assertSee('Nation sync (manual)')
            ->assertDontSee('Backups');

        $this->actingAs($diagnosticAdmin)
            ->get(route('admin.settings.security-retention'))
            ->assertOk()
            ->assertSee('Backups')
            ->assertSee('Audit log retention')
            ->assertDontSee('Account Inactivity Auto-Disable');

        $accountManager = $this->createAdmin(['manage-accounts'], 970002);

        $this->actingAs($accountManager)
            ->get(route('admin.settings.finance-policy'))
            ->assertOk()
            ->assertSee('Auto Withdraw')
            ->assertDontSee('Loan Payments')
            ->assertDontSee('Grant Approvals');

        $this->actingAs($accountManager)->get(route('admin.settings.public-site'))->assertForbidden();
        $this->actingAs($accountManager)->get(route('admin.settings.security-retention'))->assertForbidden();

        $userManager = $this->createAdmin(['edit-users'], 970003);

        $this->actingAs($userManager)
            ->get(route('admin.settings.security-retention'))
            ->assertOk()
            ->assertSee('Account Inactivity Auto-Disable')
            ->assertDontSee('Backups')
            ->assertDontSee('Audit log retention');

        $this->actingAs($userManager)->get(route('admin.settings.finance-policy'))->assertForbidden();
    }

    public function test_homepage_save_changes_only_public_site_keys_even_with_extra_domain_input(): void
    {
        $admin = $this->createAdmin(['view-diagnostic-info']);
        $this->seedSettings([
            'home_headline' => 'Old headline',
            'home_tagline' => 'Old tagline',
            'home_about' => 'Old about',
            'home_stats_intro' => 'Old stats',
            'home_closing_text' => 'Old closing',
            'home_hero_badge' => 'Old badge',
            'home_cta_label' => 'Old CTA',
            'home_highlights' => json_encode(['Old highlight'], JSON_THROW_ON_ERROR),
            'require_discord_verification' => '0',
            'auto_withdraw_enabled' => '0',
            'backups_enabled' => '0',
        ]);
        $before = $this->settingsSnapshot();

        $this->actingAs($admin)
            ->post(route('admin.settings.homepage'), [
                'home_headline' => 'New headline',
                'home_tagline' => 'New tagline',
                'home_about' => 'New about',
                'home_stats_intro' => 'New stats',
                'home_closing_text' => 'New closing',
                'home_hero_badge' => 'New badge',
                'home_cta_label' => 'New CTA',
                'home_highlights' => ['First', 'Second'],
                'require_discord_verification' => '1',
                'auto_withdraw_enabled' => '1',
                'backups_enabled' => '1',
            ])
            ->assertRedirect(route('admin.settings'));

        $this->assertEqualsCanonicalizing([
            'home_headline',
            'home_tagline',
            'home_about',
            'home_stats_intro',
            'home_closing_text',
            'home_hero_badge',
            'home_cta_label',
            'home_highlights',
        ], $this->changedSettingKeys($before));
        $this->assertSame('0', Setting::query()->where('key', 'require_discord_verification')->value('value'));
        $this->assertSame('0', Setting::query()->where('key', 'auto_withdraw_enabled')->value('value'));
        $this->assertSame('0', Setting::query()->where('key', 'backups_enabled')->value('value'));
    }

    public function test_discord_finance_and_retention_forms_ignore_cross_section_fields(): void
    {
        $this->seedSettings([
            'discord_alliance_departure_enabled' => '0',
            'discord_alliance_departure_channel_id' => 'old-channel',
            'require_discord_verification' => '0',
            'auto_withdraw_enabled' => '0',
            'grant_approvals_enabled' => '1',
            'backups_enabled' => '0',
            'audit_log_retention_days' => '365',
            'user_inactivity_auto_disable_enabled' => '0',
        ]);

        $diagnosticAdmin = $this->createAdmin(['view-diagnostic-info']);
        $before = $this->settingsSnapshot();

        $this->actingAs($diagnosticAdmin)
            ->post(route('admin.settings.discord.departure'), [
                'discord_alliance_departure_enabled' => '1',
                'discord_alliance_departure_channel_id' => 'new-channel',
                'require_discord_verification' => '1',
                'backups_enabled' => '1',
            ])
            ->assertRedirect(route('admin.settings'));

        $this->assertEqualsCanonicalizing([
            'discord_alliance_departure_enabled',
            'discord_alliance_departure_channel_id',
        ], $this->changedSettingKeys($before));
        $this->assertSame('0', Setting::query()->where('key', 'require_discord_verification')->value('value'));
        $this->assertSame('0', Setting::query()->where('key', 'backups_enabled')->value('value'));

        $accountManager = $this->createAdmin(['manage-accounts'], 970004);
        $before = $this->settingsSnapshot();

        $this->actingAs($accountManager)
            ->post(route('admin.settings.auto-withdraw'), [
                'auto_withdraw_enabled' => '1',
                'grant_approvals_enabled' => '0',
                'backups_enabled' => '1',
            ])
            ->assertRedirect(route('admin.settings'));

        $this->assertSame(['auto_withdraw_enabled'], $this->changedSettingKeys($before));
        $this->assertSame('1', Setting::query()->where('key', 'grant_approvals_enabled')->value('value'));
        $this->assertSame('0', Setting::query()->where('key', 'backups_enabled')->value('value'));

        $before = $this->settingsSnapshot();

        $this->actingAs($diagnosticAdmin)
            ->post(route('admin.settings.audit-retention'), [
                'audit_log_retention_days' => '730',
                'user_inactivity_auto_disable_enabled' => '1',
                'backups_enabled' => '1',
            ])
            ->assertRedirect(route('admin.settings'));

        $this->assertSame(['audit_log_retention_days'], $this->changedSettingKeys($before));
        $this->assertSame('0', Setting::query()->where('key', 'user_inactivity_auto_disable_enabled')->value('value'));
        $this->assertSame('0', Setting::query()->where('key', 'backups_enabled')->value('value'));
    }

    public function test_discord_routes_preserve_setting_serialization_and_audit_actions(): void
    {
        $admin = $this->createAdmin(['view-diagnostic-info']);
        $this->seedSettings([
            'require_discord_verification' => '0',
            'discord_private_notifications_enabled' => '0',
            'discord_city_tier_bucket_size' => '10',
            'discord_alliance_departure_enabled' => '0',
            'discord_alliance_departure_channel_id' => '',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.settings.discord'), ['require_discord_verification' => '1'])
            ->assertRedirect(route('admin.settings'));

        $this->actingAs($admin)
            ->post(route('admin.settings.discord.private-notifications'), [
                'discord_private_notifications_enabled' => '1',
            ])
            ->assertRedirect(route('admin.settings'));

        $this->actingAs($admin)
            ->post(route('admin.settings.discord.city-tiers'), [
                'discord_city_tier_bucket_size' => '25',
            ])
            ->assertRedirect(route('admin.settings'));

        $this->actingAs($admin)
            ->post(route('admin.settings.discord.departure'), [
                'discord_alliance_departure_enabled' => '1',
                'discord_alliance_departure_channel_id' => '123456789012345678',
            ])
            ->assertRedirect(route('admin.settings'));

        $this->assertDatabaseHas('settings', ['key' => 'require_discord_verification', 'value' => '1']);
        $this->assertDatabaseHas('settings', ['key' => 'discord_private_notifications_enabled', 'value' => '1']);
        $this->assertDatabaseHas('settings', ['key' => 'discord_city_tier_bucket_size', 'value' => '25']);
        $this->assertDatabaseHas('settings', ['key' => 'discord_alliance_departure_enabled', 'value' => '1']);
        $this->assertDatabaseHas('settings', [
            'key' => 'discord_alliance_departure_channel_id',
            'value' => '123456789012345678',
        ]);

        foreach ([
            'discord_verification_requirement_updated',
            'discord_private_notifications_updated',
            'discord_city_tier_settings_updated',
            'discord_departure_settings_updated',
        ] as $action) {
            $this->assertDatabaseHas('audit_logs', [
                'actor_id' => $admin->id,
                'category' => 'settings',
                'action' => $action,
                'outcome' => 'success',
            ]);
        }
    }

    public function test_finance_and_retention_routes_preserve_multi_field_workflows(): void
    {
        $admin = $this->createAdmin([
            'view-diagnostic-info',
            'manage-accounts',
            'manage-loans',
            'manage-grants',
            'edit-users',
        ]);
        $this->seedSettings([
            'auto_withdraw_enabled' => '0',
            'loan_payments_enabled' => '1',
            'loan_payments_paused_at' => '',
            'grant_approvals_enabled' => '1',
            'backups_enabled' => '0',
            'audit_log_retention_days' => '365',
            'user_inactivity_auto_disable_enabled' => '0',
            'user_inactivity_auto_disable_days' => '90',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.settings.auto-withdraw'), ['auto_withdraw_enabled' => '1'])
            ->assertRedirect(route('admin.settings'));
        $this->actingAs($admin)
            ->post(route('admin.settings.loan-payments'), ['loan_payments_enabled' => '0'])
            ->assertRedirect(route('admin.settings'));

        $pausedAt = Setting::query()->where('key', 'loan_payments_paused_at')->value('value');
        $this->assertIsString($pausedAt);
        $this->assertNotSame('', $pausedAt);

        $this->actingAs($admin)
            ->post(route('admin.settings.loan-payments'), ['loan_payments_enabled' => '1'])
            ->assertRedirect(route('admin.settings'));
        $this->actingAs($admin)
            ->post(route('admin.settings.grants.approvals'), ['grant_approvals_enabled' => '0'])
            ->assertRedirect(route('admin.settings'));
        $this->actingAs($admin)
            ->post(route('admin.settings.backups'), ['backups_enabled' => '1'])
            ->assertRedirect(route('admin.settings'));
        $this->actingAs($admin)
            ->post(route('admin.settings.audit-retention'), ['audit_log_retention_days' => '730'])
            ->assertRedirect(route('admin.settings'));
        $this->actingAs($admin)
            ->post(route('admin.settings.account-inactivity-auto-disable'), [
                'user_inactivity_auto_disable_enabled' => '1',
                'user_inactivity_auto_disable_days' => '120',
            ])
            ->assertRedirect(route('admin.settings'));

        $this->assertDatabaseHas('settings', ['key' => 'auto_withdraw_enabled', 'value' => '1']);
        $this->assertDatabaseHas('settings', ['key' => 'loan_payments_enabled', 'value' => '1']);
        $this->assertDatabaseHas('settings', ['key' => 'loan_payments_paused_at', 'value' => '']);
        $this->assertDatabaseHas('settings', ['key' => 'grant_approvals_enabled', 'value' => '0']);
        $this->assertDatabaseHas('settings', ['key' => 'backups_enabled', 'value' => '1']);
        $this->assertDatabaseHas('settings', ['key' => 'audit_log_retention_days', 'value' => '730']);
        $this->assertDatabaseHas('settings', ['key' => 'user_inactivity_auto_disable_enabled', 'value' => '1']);
        $this->assertDatabaseHas('settings', ['key' => 'user_inactivity_auto_disable_days', 'value' => '120']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'loan_payments_resumed', 'outcome' => 'success']);
    }

    public function test_favicon_route_preserves_storage_setting_and_audit_contract(): void
    {
        Storage::fake('public');
        $admin = $this->createAdmin(['view-diagnostic-info']);

        $this->actingAs($admin)
            ->post(route('admin.settings.favicon'), [
                'favicon' => UploadedFile::fake()->image('favicon.jpg', 64, 64),
            ])
            ->assertRedirect(route('admin.settings'));

        Storage::disk('public')->assertExists('branding/favicon.jpg');
        $this->assertDatabaseHas('settings', [
            'key' => 'favicon_path',
            'value' => 'branding/favicon.jpg',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $admin->id,
            'category' => 'settings',
            'action' => 'favicon_updated',
            'outcome' => 'success',
        ]);
    }

    public function test_validation_failure_preserves_values_and_uses_only_the_form_error_fields(): void
    {
        $admin = $this->createAdmin(['manage-accounts']);
        $this->seedSettings([
            'auto_withdraw_enabled' => '0',
            'backups_enabled' => '0',
        ]);

        $this->actingAs($admin)
            ->from(route('admin.settings.finance-policy'))
            ->post(route('admin.settings.auto-withdraw'), [
                'auto_withdraw_enabled' => 'not-a-boolean',
                'backups_enabled' => '1',
            ])
            ->assertRedirect(route('admin.settings.finance-policy'))
            ->assertSessionHasErrors(['auto_withdraw_enabled'])
            ->assertSessionDoesntHaveErrors(['backups_enabled']);

        $this->assertDatabaseHas('settings', ['key' => 'auto_withdraw_enabled', 'value' => '0']);
        $this->assertDatabaseHas('settings', ['key' => 'backups_enabled', 'value' => '0']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'auto_withdraw_toggle']);
    }

    /** @param array<string, string> $values */
    private function seedSettings(array $values): void
    {
        $values = [
            'require_mfa_all_users' => '0',
            'require_mfa_admins' => '0',
            ...$values,
        ];

        foreach ($values as $key => $value) {
            Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        }
    }

    /** @return array<string, string|null> */
    private function settingsSnapshot(): array
    {
        return Setting::query()->orderBy('key')->pluck('value', 'key')->all();
    }

    /**
     * @param  array<string, string|null>  $before
     * @return array<int, string>
     */
    private function changedSettingKeys(array $before): array
    {
        $after = $this->settingsSnapshot();

        return collect(array_unique([...array_keys($before), ...array_keys($after)]))
            ->filter(fn (string $key): bool => ($before[$key] ?? null) !== ($after[$key] ?? null))
            ->sort()
            ->values()
            ->all();
    }

    /** @param array<int, string> $permissions */
    private function createAdmin(array $permissions, int $nationId = 970001): User
    {
        $admin = $this->createVerifiedAdmin(['nation_id' => $nationId]);
        $this->attachDiscordAccount($admin, ['discord_id' => (string) ($nationId + 1_000_000)]);

        return $this->grantPermissions($admin, $permissions);
    }
}
