<?php

namespace Tests\Feature\Services\Settings;

use App\Models\RecruitmentMessage;
use App\Models\Setting;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SettingServiceCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_guardrail_constants_keep_their_exact_values(): void
    {
        $this->assertSame(10, SettingService::DEFAULT_DISCORD_CITY_TIER_BUCKET_SIZE);
        $this->assertSame(5_000_000, SettingService::DEFAULT_LOTTERY_TICKET_PRICE_CENTS);
        $this->assertSame(9_000, SettingService::DEFAULT_LOTTERY_JACKPOT_BASIS_POINTS);
        $this->assertSame(100, SettingService::DEFAULT_LOTTERY_MAX_TICKETS_PER_PURCHASE);
        $this->assertSame(10_000, SettingService::DEFAULT_LOTTERY_MAX_TICKETS_PER_NATION);
        $this->assertSame(1_000_000_000, SettingService::MAX_LOTTERY_TICKET_PRICE_CENTS);
        $this->assertSame(500, SettingService::MAX_LOTTERY_TICKETS_PER_PURCHASE);
        $this->assertSame(10_000, SettingService::MAX_LOTTERY_TICKETS_PER_NATION);
        $this->assertSame(0, SettingService::DEFAULT_RAID_ACTIVITY_CITY_THRESHOLD);
        $this->assertSame(0, SettingService::DEFAULT_RAID_MINIMUM_INACTIVE_TURNS);
        $this->assertSame(1000, SettingService::MAX_RAID_ACTIVITY_CITY_THRESHOLD);
        $this->assertSame(4380, SettingService::MAX_RAID_MINIMUM_INACTIVE_TURNS);
    }

    public function test_data_sync_defaults_and_manual_nation_aliases_are_preserved(): void
    {
        $this->assertSame(0, SettingService::getLastScannedBankRecordId());
        $this->assertSame('0', $this->storedValue('last_bank_record_id'));
        $this->assertNull(SettingService::getCityAverage());
        $this->assertNull(SettingService::getCityAverageUpdatedAt());
        $this->assertSame(40, SettingService::getTopRaidable());
        $this->assertSame('40', $this->storedValue('raid_top_alliance_cap'));
        $this->assertSame(0, SettingService::getRaidActivityCityThreshold());
        $this->assertSame('0', $this->storedValue('raid_activity_city_threshold'));
        $this->assertSame(0, SettingService::getRaidMinimumInactiveTurns());
        $this->assertSame('0', $this->storedValue('raid_minimum_inactive_turns'));

        $this->assertSame('', SettingService::getLastNationSyncBatchId());
        $this->assertSame('', $this->storedValue('last_nation_sync_batch_id'));

        SettingService::setLastNationSyncBatchId('manual-batch-a');

        $this->assertSame('manual-batch-a', SettingService::getLastManualNationSyncBatchId());

        SettingService::setLastManualNationSyncBatchId('manual-batch-b');

        $this->assertSame('manual-batch-b', SettingService::getLastNationSyncBatchId());
        $this->assertSame('manual-batch-b', $this->storedValue('last_nation_sync_batch_id'));
        $this->assertDatabaseMissing('settings', ['key' => 'last_manual_nation_sync_batch_id']);

        $this->assertSame('', SettingService::getLastRollingNationSyncBatchId());
        $this->assertSame('', SettingService::getLastAllianceSyncBatchId());
        $this->assertSame('', SettingService::getLastWarSyncBatchId());
        $this->assertSame('', $this->storedValue('last_rolling_nation_sync_batch_id'));
        $this->assertSame('', $this->storedValue('last_alliance_sync_batch_id'));
        $this->assertSame('', $this->storedValue('last_war_sync_batch_id'));
    }

    public function test_finance_policy_defaults_and_mutation_normalization_are_preserved(): void
    {
        $this->assertSame([
            'sales_enabled' => true,
            'ticket_price_cents' => SettingService::DEFAULT_LOTTERY_TICKET_PRICE_CENTS,
            'jackpot_basis_points' => SettingService::DEFAULT_LOTTERY_JACKPOT_BASIS_POINTS,
            'max_tickets_per_purchase' => SettingService::DEFAULT_LOTTERY_MAX_TICKETS_PER_PURCHASE,
            'max_tickets_per_nation' => SettingService::DEFAULT_LOTTERY_MAX_TICKETS_PER_NATION,
        ], SettingService::getLotterySettings());
        $this->assertDatabaseMissing('settings', ['key' => 'lottery_configuration']);

        $this->assertFalse(SettingService::isWarAidEnabled());
        $this->assertFalse(SettingService::isRebuildingEnabled());
        $this->assertSame(1, SettingService::getRebuildingCycleId());
        $this->assertNull(SettingService::getRebuildingLastEstimateRefreshAt());
        $this->assertTrue(SettingService::isAutoWithdrawEnabled());
        $this->assertTrue(SettingService::isLoanPaymentsEnabled());
        $this->assertTrue(SettingService::isLoanApplicationsEnabled());
        $this->assertSame(0.0, SettingService::getDefaultLoanInterestRate());
        $this->assertTrue(SettingService::isGrantApprovalsEnabled());
        $this->assertNull(SettingService::getLoanPaymentsPausedAt());
        $this->assertSame(0, SettingService::getDirectDepositId());
        $this->assertSame(0, SettingService::getDirectDepositFallbackId());
        $this->assertFalse(SettingService::isDirectDepositEnabled());
        $this->assertSame(0, SettingService::getGrowthCirclesTaxId());
        $this->assertSame(0, SettingService::getGrowthCirclesFallbackTaxId());
        $this->assertFalse(SettingService::isGrowthCirclesEnabled());
        $this->assertSame(0, SettingService::getWithdrawMaxDailyCount());

        $this->assertSame('0', $this->storedValue('war_aid_enabled'));
        $this->assertSame('0', $this->storedValue('rebuilding_enabled'));
        $this->assertSame('1', $this->storedValue('rebuilding_cycle_id'));
        $this->assertSame('1', $this->storedValue('auto_withdraw_enabled'));
        $this->assertSame('1', $this->storedValue('loan_payments_enabled'));
        $this->assertSame('1', $this->storedValue('loan_applications_enabled'));
        $this->assertSame('0', $this->storedValue('loan_default_interest_rate'));
        $this->assertSame('1', $this->storedValue('grant_approvals_enabled'));
        $this->assertSame('0', $this->storedValue('dd_tax_id'));
        $this->assertSame('0', $this->storedValue('dd_fallback_tax_id'));
        $this->assertSame('0', $this->storedValue('growth_circles_tax_id'));
        $this->assertSame('0', $this->storedValue('growth_circles_fallback_tax_id'));
        $this->assertSame('0', $this->storedValue('withdraw_max_daily_count'));

        SettingService::setDefaultLoanInterestRate(-2.5);
        SettingService::setRebuildingCycleId(0);
        SettingService::setWithdrawMaxDailyCount(-10);

        $this->assertSame('0', $this->storedValue('loan_default_interest_rate'));
        $this->assertSame('1', $this->storedValue('rebuilding_cycle_id'));
        $this->assertSame('0', $this->storedValue('withdraw_max_daily_count'));
    }

    public function test_discord_security_and_inactivity_default_persistence_contract_is_preserved(): void
    {
        config()->set('audit.retention_days_default', 123);

        $this->assertFalse(SettingService::isDiscordVerificationRequired());
        $this->assertFalse(SettingService::areDiscordPrivateNotificationsEnabled());
        $this->assertSame('', SettingService::getDiscordWarAlertChannelId());
        $this->assertSame(SettingService::DEFAULT_DISCORD_CITY_TIER_BUCKET_SIZE, SettingService::getDiscordCityTierBucketSize());
        $this->assertSame('', SettingService::getDiscordWarRoomForumId());
        $this->assertSame('', SettingService::getDiscordWarRoomDefenseRoleId());
        $this->assertTrue(SettingService::isWarCounterAutoCreationEnabled());
        $this->assertSame('', SettingService::getDiscordAllianceDepartureChannelId());
        $this->assertFalse(SettingService::isDiscordWarAlertEnabled());
        $this->assertFalse(SettingService::isDiscordAllianceDepartureEnabled());
        $this->assertFalse(SettingService::isBeigeAlertsEnabled());
        $this->assertSame('', SettingService::getBeigeAlertsDiscordChannelId());

        $this->assertFalse(SettingService::isMfaRequiredForAllUsers());
        $this->assertFalse(SettingService::isMfaRequiredForAdmins());
        $this->assertFalse(SettingService::isBackupsEnabled());
        $this->assertSame(123, SettingService::getAuditLogRetentionDays());
        $this->assertFalse(SettingService::isUserInactivityAutoDisableEnabled());
        $this->assertSame(90, SettingService::getUserInactivityAutoDisableDays());

        $this->assertFalse(SettingService::isInactivityModeEnabled());
        $this->assertSame(72, SettingService::getInactivityThresholdHours());
        $this->assertSame([], SettingService::getInactivityActions());
        $this->assertSame(24, SettingService::getInactivityCooldownHours());
        $this->assertSame('', SettingService::getInactivityDiscordChannelId());
        $this->assertFalse(SettingService::isInactivityRepeatNotificationsEnabled());

        $persistedDefaults = [
            'require_discord_verification' => '0',
            'discord_war_room_defense_role_id' => '',
            'war_counter_auto_creation_enabled' => '1',
            'beige_alerts_enabled' => '0',
            'require_mfa_all_users' => '0',
            'require_mfa_admins' => '0',
            'backups_enabled' => '0',
            'audit_log_retention_days' => '123',
            'user_inactivity_auto_disable_days' => '90',
            'inactivity_mode_enabled' => '0',
            'inactivity_threshold_hours' => '72',
            'inactivity_actions' => '[]',
            'inactivity_notification_cooldown_hours' => '24',
            'inactivity_repeat_notifications_enabled' => '0',
        ];

        foreach ($persistedDefaults as $key => $value) {
            $this->assertSame($value, $this->storedValue($key), $key);
        }

        foreach ([
            'discord_private_notifications_enabled',
            'discord_war_alert_channel_id',
            'discord_city_tier_bucket_size',
            'discord_war_room_forum_id',
            'discord_alliance_departure_channel_id',
            'discord_war_alert_enabled',
            'discord_alliance_departure_enabled',
            'beige_alerts_discord_channel_id',
            'user_inactivity_auto_disable_enabled',
            'inactivity_discord_channel_id',
        ] as $key) {
            $this->assertDatabaseMissing('settings', ['key' => $key]);
        }
    }

    public function test_application_recruitment_and_mmr_defaults_use_their_current_storage(): void
    {
        config()->set('app.name', 'Nexus Test');
        RecruitmentMessage::query()->delete();

        $this->assertFalse(SettingService::isApplicationsEnabled());
        $this->assertSame(0, SettingService::getApplicationsApprovedPositionId());
        $this->assertSame('', SettingService::getApplicationsDiscordApplicantRoleId());
        $this->assertSame('', SettingService::getApplicationsDiscordIaRoleId());
        $this->assertSame('', SettingService::getApplicationsDiscordMemberRoleId());
        $this->assertSame('', SettingService::getApplicationsDiscordInterviewCategoryId());
        $this->assertSame('', SettingService::getApplicationsApprovalAnnouncementChannelId());
        $this->assertSame(
            'Welcome to the alliance! A new member has been approved.',
            SettingService::getApplicationsApprovalMessageTemplate(),
        );

        $this->assertFalse(SettingService::isRecruitmentEnabled());
        $this->assertFalse(SettingService::isRecruitmentFollowUpEnabled());
        $this->assertSame('Nexus Test Recruitment', SettingService::getRecruitmentPrimarySubject());
        $this->assertSame('Checking in from Nexus Test', SettingService::getRecruitmentFollowUpSubject());

        $primaryMessage = SettingService::getRecruitmentPrimaryMessage();
        $followUpMessage = SettingService::getRecruitmentFollowUpMessage();

        $this->assertStringContainsString('The team at Nexus Test', $primaryMessage);
        $this->assertStringContainsString("we'd love to have you at Nexus Test", $followUpMessage);
        $this->assertSame($primaryMessage, RecruitmentMessage::query()->where('type', 'primary')->value('message'));
        $this->assertSame($followUpMessage, RecruitmentMessage::query()->where('type', 'follow_up')->value('message'));
        $this->assertDatabaseMissing('settings', ['key' => 'recruitment_primary_message']);
        $this->assertDatabaseMissing('settings', ['key' => 'recruitment_follow_up_message']);

        SettingService::setRecruitmentPrimaryMessage(str_repeat('<p>Long message</p>', 40));

        $this->assertSame(
            str_repeat('<p>Long message</p>', 40),
            RecruitmentMessage::query()->where('type', 'primary')->value('message'),
        );
        $this->assertDatabaseMissing('settings', ['key' => 'recruitment_primary_message']);

        $this->assertFalse(SettingService::getMMRAssistantEnabled());
        $this->assertSame([], SettingService::getMMRResourceWeights());
        $this->assertSame('0', $this->storedValue('mmr_assistant_enabled'));
        $this->assertDatabaseMissing('settings', ['key' => 'mmr_resource_weights']);
    }

    public function test_public_site_defaults_are_dynamic_and_do_not_persist_on_read(): void
    {
        $this->assertNull(SettingService::getFaviconPath());
        $this->assertSame('Build your next chapter with Black Knights', SettingService::getHomepageHeadline('Black Knights'));
        $this->assertSame(
            'Black Knights is where ambitious nations find real support, sharp coordination, and a community worth staying for.',
            SettingService::getHomepageTagline('Black Knights'),
        );
        $this->assertSame(
            'Black Knights is for members who want a steady alliance, active leadership, and a community that works well together.',
            SettingService::getHomepageAbout('Black Knights'),
        );
        $this->assertSame([
            'A short application and a clear next step after you apply.',
            'Help with growth, coordination, and the day-to-day work of building a nation.',
            'An active alliance that is easy to settle into.',
        ], SettingService::getHomepageHighlights());
        $this->assertSame('A quick look at where the alliance stands today.', SettingService::getHomepageStatsIntro());
        $this->assertSame(
            'If Black Knights feels like the right fit, send in your application and come meet the team.',
            SettingService::getHomepageClosingText('Black Knights'),
        );
        $this->assertSame('Recruiting now', SettingService::getHomepageHeroBadge());
        $this->assertSame('Start your application', SettingService::getHomepageCtaLabel());

        $this->assertSame(0, Setting::query()->where('key', 'like', 'home_%')->count());
        $this->assertDatabaseMissing('settings', ['key' => 'favicon_path']);

        SettingService::setHomepageHeadline('Custom headline');
        SettingService::setHomepageHighlights([' First ', '', 42, 'Second']);
        SettingService::setFaviconPath(null);

        $this->assertSame('Custom headline', SettingService::getHomepageHeadline('Ignored'));
        $this->assertSame(['First', 'Second'], SettingService::getHomepageHighlights());
        $this->assertSame('', $this->storedValue('favicon_path'));
    }

    public function test_json_and_timestamp_serialization_are_preserved_exactly(): void
    {
        $timestamp = Carbon::parse('2026-08-06 12:34:56', 'America/Chicago');

        SettingService::setInactivityActions(['disable_user', 10, 'notify_staff', null]);
        SettingService::setMMRResourceWeights(['cash' => 1, 'steel' => 2.5]);
        SettingService::setLotterySettings([
            'sales_enabled' => false,
            'ticket_price_cents' => 50,
            'jackpot_basis_points' => 20000,
            'max_tickets_per_purchase' => 600,
            'max_tickets_per_nation' => 50,
        ]);
        SettingService::setCityAverageUpdatedAt($timestamp);
        SettingService::setRebuildingLastEstimateRefreshAt($timestamp);
        SettingService::setLoanPaymentsPausedAt($timestamp);

        $this->assertSame('["disable_user","notify_staff"]', $this->storedValue('inactivity_actions'));
        $this->assertSame('{"cash":1,"steel":2.5}', $this->storedValue('mmr_resource_weights'));
        $this->assertSame(
            '{"sales_enabled":false,"ticket_price_cents":100,"jackpot_basis_points":10000,"max_tickets_per_purchase":50,"max_tickets_per_nation":50}',
            $this->storedValue('lottery_configuration'),
        );
        $this->assertSame('2026-08-06T12:34:56-05:00', $this->storedValue('pw_city_average_updated_at'));
        $this->assertSame('2026-08-06T12:34:56-05:00', $this->storedValue('rebuilding_last_estimate_refresh_at'));
        $this->assertSame('2026-08-06T12:34:56-05:00', $this->storedValue('loan_payments_paused_at'));
        $this->assertTrue($timestamp->equalTo(SettingService::getCityAverageUpdatedAt()));
        $this->assertTrue($timestamp->equalTo(SettingService::getRebuildingLastEstimateRefreshAt()));
        $this->assertTrue($timestamp->equalTo(SettingService::getLoanPaymentsPausedAt()));

        SettingService::setLoanPaymentsPausedAt(null);

        $this->assertSame('', $this->storedValue('loan_payments_paused_at'));
        $this->assertNull(SettingService::getLoanPaymentsPausedAt());
    }

    public function test_reads_are_not_cached_and_observe_out_of_band_mutations(): void
    {
        SettingService::setValue('uncached_probe', 'first');

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->assertSame('first', SettingService::getValue('uncached_probe'));

        DB::table('settings')->where('key', 'uncached_probe')->update(['value' => 'second']);

        $this->assertSame('second', SettingService::getValue('uncached_probe'));

        $settingsSelects = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_starts_with(strtolower(ltrim($query['query'])), 'select')
                && str_contains(strtolower($query['query']), 'settings'))
            ->count();

        DB::disableQueryLog();

        $this->assertSame(2, $settingsSelects);
    }

    private function storedValue(string $key): ?string
    {
        $value = Setting::query()->where('key', $key)->value('value');

        return is_null($value) ? null : (string) $value;
    }
}
