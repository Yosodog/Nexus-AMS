<?php

namespace Tests\Feature\Services\Settings;

use App\Models\RecruitmentMessage;
use App\Services\Settings\ApplicationSettings;
use App\Services\Settings\DataSyncSettings;
use App\Services\Settings\DiscordSettings;
use App\Services\Settings\FinancePolicySettings;
use App\Services\Settings\InactivitySettings;
use App\Services\Settings\MilitaryReadinessSettings;
use App\Services\Settings\PublicSiteSettings;
use App\Services\Settings\RecruitmentSettings;
use App\Services\Settings\SecurityRetentionSettings;
use App\Services\Settings\SettingValueStore;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingDomainServicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_value_store_preserves_raw_mutation_and_both_string_default_modes(): void
    {
        $store = app(SettingValueStore::class);

        $store->set('raw_value', 'first');

        $this->assertSame('first', $store->get('raw_value'));
        $this->assertSame('persisted default', $store->getString('persisted_string', 'persisted default'));
        $this->assertDatabaseHas('settings', [
            'key' => 'persisted_string',
            'value' => 'persisted default',
        ]);
        $this->assertSame('transient default', $store->getStringWithoutPersisting('transient_string', 'transient default'));
        $this->assertDatabaseMissing('settings', ['key' => 'transient_string']);

        SettingService::setValue('raw_value', 'second');

        $this->assertSame('second', $store->get('raw_value'));
    }

    public function test_data_sync_service_and_compatibility_facade_share_mutations_and_aliases(): void
    {
        $settings = app(DataSyncSettings::class);

        $settings->setLastManualNationSyncBatchId('manual-1');
        $settings->setCityAverage(21.75);

        $this->assertSame('manual-1', SettingService::getLastNationSyncBatchId());
        $this->assertSame(21.75, SettingService::getCityAverage());

        SettingService::setLastNationSyncBatchId('manual-2');
        SettingService::setTopRaidable(55);

        $this->assertSame('manual-2', $settings->getLastManualNationSyncBatchId());
        $this->assertSame(55, $settings->getTopRaidable());
    }

    public function test_public_site_service_and_compatibility_facade_share_values_without_persisting_defaults(): void
    {
        $settings = app(PublicSiteSettings::class);

        $this->assertSame('Build your next chapter with BK', $settings->getHomepageHeadline('BK'));
        $this->assertDatabaseMissing('settings', ['key' => 'home_headline']);

        $settings->setHomepageHeadline('A custom headline');
        SettingService::setHomepageHeroBadge('Open applications');

        $this->assertSame('A custom headline', SettingService::getHomepageHeadline('Ignored'));
        $this->assertSame('Open applications', $settings->getHomepageHeroBadge());
    }

    public function test_discord_service_and_compatibility_facade_share_fallbacks_and_mutations(): void
    {
        $settings = app(DiscordSettings::class);

        $settings->setWarAlertChannelId('1234');
        $settings->setWarAlertEnabled(true);

        $this->assertSame('1234', SettingService::getDiscordAllianceDepartureChannelId());
        $this->assertTrue(SettingService::isDiscordAllianceDepartureEnabled());

        SettingService::setDiscordWarRoomDefenseRoleId('5678');
        SettingService::setDiscordCityTierBucketSize(200);

        $this->assertSame('5678', $settings->getWarRoomDefenseRoleId());
        $this->assertSame(100, $settings->getCityTierBucketSize());
    }

    public function test_finance_policy_service_and_compatibility_facade_share_normalized_mutations(): void
    {
        $settings = app(FinancePolicySettings::class);

        $settings->setDefaultLoanInterestRate(3.25);
        $settings->setDirectDepositId(42);

        $this->assertSame(3.25, SettingService::getDefaultLoanInterestRate());
        $this->assertTrue(SettingService::isDirectDepositEnabled());

        SettingService::setRebuildingCycleId(-1);
        SettingService::setWithdrawMaxDailyCount(-5);

        $this->assertSame(1, $settings->getRebuildingCycleId());
        $this->assertSame(0, $settings->getWithdrawMaxDailyCount());
    }

    public function test_security_retention_service_and_compatibility_facade_share_mutations(): void
    {
        $settings = app(SecurityRetentionSettings::class);

        $settings->setMfaRequiredForAdmins(true);
        $settings->setAuditLogRetentionDays(0);

        $this->assertTrue(SettingService::isMfaRequiredForAdmins());
        $this->assertSame(1, SettingService::getAuditLogRetentionDays());

        SettingService::setBackupsEnabled(true);
        SettingService::setUserInactivityAutoDisableDays(45);

        $this->assertTrue($settings->isBackupsEnabled());
        $this->assertSame(45, $settings->getUserInactivityAutoDisableDays());
    }

    public function test_inactivity_service_and_compatibility_facade_share_json_and_mutations(): void
    {
        $settings = app(InactivitySettings::class);

        $settings->setEnabled(true);
        $settings->setActions(['notify', 3, 'disable']);

        $this->assertTrue(SettingService::isInactivityModeEnabled());
        $this->assertSame(['notify', 'disable'], SettingService::getInactivityActions());

        SettingService::setInactivityThresholdHours(0);
        SettingService::setInactivityDiscordChannelId('9876');

        $this->assertSame(1, $settings->getThresholdHours());
        $this->assertSame('9876', $settings->getDiscordChannelId());
    }

    public function test_application_service_and_compatibility_facade_share_mutations(): void
    {
        $settings = app(ApplicationSettings::class);

        $settings->setEnabled(true);
        $settings->setDiscordApplicantRoleId('applicant-role');

        $this->assertTrue(SettingService::isApplicationsEnabled());
        $this->assertSame('applicant-role', SettingService::getApplicationsDiscordApplicantRoleId());

        SettingService::setApplicationsApprovedPositionId(8);
        SettingService::setApplicationsApprovalMessageTemplate('Approved!');

        $this->assertSame(8, $settings->getApprovedPositionId());
        $this->assertSame('Approved!', $settings->getApprovalMessageTemplate());
    }

    public function test_recruitment_service_and_compatibility_facade_share_separate_message_storage(): void
    {
        config()->set('app.name', 'Nexus Test');
        RecruitmentMessage::query()->delete();
        $settings = app(RecruitmentSettings::class);

        $settings->setEnabled(true);
        $settings->setPrimaryMessage('<p>Direct domain message</p>');

        $this->assertTrue(SettingService::isRecruitmentEnabled());
        $this->assertSame('<p>Direct domain message</p>', SettingService::getRecruitmentPrimaryMessage());
        $this->assertDatabaseMissing('settings', ['key' => 'recruitment_primary_message']);

        SettingService::setRecruitmentFollowUpSubject('  Follow up now  ');
        SettingService::setRecruitmentFollowUpMessage('<p>Facade message</p>');

        $this->assertSame('Follow up now', $settings->getFollowUpSubject());
        $this->assertSame('<p>Facade message</p>', $settings->getFollowUpMessage());
        $this->assertDatabaseHas('recruitment_messages', [
            'type' => 'follow_up',
            'message' => '<p>Facade message</p>',
        ]);
    }

    public function test_military_readiness_service_and_compatibility_facade_share_json_mutations(): void
    {
        $settings = app(MilitaryReadinessSettings::class);

        $settings->setAssistantEnabled(true);
        $settings->setResourceWeights(['cash' => 1, 'steel' => '2.5']);

        $this->assertTrue(SettingService::getMMRAssistantEnabled());
        $this->assertSame(['cash' => 1.0, 'steel' => 2.5], SettingService::getMMRResourceWeights());

        SettingService::setMMRAssistantEnabled(false);
        SettingService::setMMRResourceWeights(['aluminum' => 4]);

        $this->assertFalse($settings->isAssistantEnabled());
        $this->assertSame(['aluminum' => 4.0], $settings->getResourceWeights());
    }
}
