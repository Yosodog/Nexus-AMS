<?php

namespace App\Services;

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
use Illuminate\Support\Carbon;

class SettingService
{
    public const DEFAULT_DISCORD_CITY_TIER_BUCKET_SIZE = DiscordSettings::DEFAULT_CITY_TIER_BUCKET_SIZE;

    public const DEFAULT_LOTTERY_TICKET_PRICE_CENTS = FinancePolicySettings::DEFAULT_LOTTERY_TICKET_PRICE_CENTS;

    public const DEFAULT_LOTTERY_JACKPOT_BASIS_POINTS = FinancePolicySettings::DEFAULT_LOTTERY_JACKPOT_BASIS_POINTS;

    public const DEFAULT_LOTTERY_MAX_TICKETS_PER_PURCHASE = FinancePolicySettings::DEFAULT_LOTTERY_MAX_TICKETS_PER_PURCHASE;

    public const DEFAULT_LOTTERY_MAX_TICKETS_PER_NATION = FinancePolicySettings::DEFAULT_LOTTERY_MAX_TICKETS_PER_NATION;

    public const MAX_LOTTERY_TICKET_PRICE_CENTS = FinancePolicySettings::MAX_LOTTERY_TICKET_PRICE_CENTS;

    public const MAX_LOTTERY_TICKETS_PER_PURCHASE = FinancePolicySettings::MAX_LOTTERY_TICKETS_PER_PURCHASE;

    public const MAX_LOTTERY_TICKETS_PER_NATION = FinancePolicySettings::MAX_LOTTERY_TICKETS_PER_NATION;

    public const DEFAULT_RAID_ACTIVITY_CITY_THRESHOLD = DataSyncSettings::DEFAULT_RAID_ACTIVITY_CITY_THRESHOLD;

    public const DEFAULT_RAID_MINIMUM_INACTIVE_TURNS = DataSyncSettings::DEFAULT_RAID_MINIMUM_INACTIVE_TURNS;

    public const MAX_RAID_ACTIVITY_CITY_THRESHOLD = DataSyncSettings::MAX_RAID_ACTIVITY_CITY_THRESHOLD;

    public const MAX_RAID_MINIMUM_INACTIVE_TURNS = DataSyncSettings::MAX_RAID_MINIMUM_INACTIVE_TURNS;

    public static function getLastScannedBankRecordId(): int
    {
        return app(DataSyncSettings::class)->getLastScannedBankRecordId();
    }

    public static function getValue(string $key): mixed
    {
        return app(SettingValueStore::class)->get($key);
    }

    public static function setValue(string $key, mixed $value): void
    {
        app(SettingValueStore::class)->set($key, $value);
    }

    /**
     * @return array{
     *     sales_enabled: bool,
     *     ticket_price_cents: int,
     *     jackpot_basis_points: int,
     *     max_tickets_per_purchase: int,
     *     max_tickets_per_nation: int
     * }
     */
    public static function getLotterySettings(): array
    {
        return app(FinancePolicySettings::class)->getLotterySettings();
    }

    /**
     * @param  array{
     *     sales_enabled: bool,
     *     ticket_price_cents: int,
     *     jackpot_basis_points: int,
     *     max_tickets_per_purchase: int,
     *     max_tickets_per_nation: int
     * }  $settings
     */
    public static function setLotterySettings(array $settings): void
    {
        app(FinancePolicySettings::class)->setLotterySettings($settings);
    }

    public static function setLastScannedBankRecordId(int $id): void
    {
        app(DataSyncSettings::class)->setLastScannedBankRecordId($id);
    }

    public static function getCityAverage(): ?float
    {
        return app(DataSyncSettings::class)->getCityAverage();
    }

    public static function setCityAverage(float $average): void
    {
        app(DataSyncSettings::class)->setCityAverage($average);
    }

    public static function getCityAverageUpdatedAt(): ?Carbon
    {
        return app(DataSyncSettings::class)->getCityAverageUpdatedAt();
    }

    public static function setCityAverageUpdatedAt(Carbon $timestamp): void
    {
        app(DataSyncSettings::class)->setCityAverageUpdatedAt($timestamp);
    }

    public static function isWarAidEnabled(): bool
    {
        return app(FinancePolicySettings::class)->isWarAidEnabled();
    }

    public static function setWarAidEnabled(bool $enabled): void
    {
        app(FinancePolicySettings::class)->setWarAidEnabled($enabled);
    }

    public static function isRebuildingEnabled(): bool
    {
        return app(FinancePolicySettings::class)->isRebuildingEnabled();
    }

    public static function setRebuildingEnabled(bool $enabled): void
    {
        app(FinancePolicySettings::class)->setRebuildingEnabled($enabled);
    }

    public static function getRebuildingCycleId(): int
    {
        return app(FinancePolicySettings::class)->getRebuildingCycleId();
    }

    public static function setRebuildingCycleId(int $cycleId): void
    {
        app(FinancePolicySettings::class)->setRebuildingCycleId($cycleId);
    }

    public static function incrementRebuildingCycleId(): int
    {
        return app(FinancePolicySettings::class)->incrementRebuildingCycleId();
    }

    public static function getRebuildingLastEstimateRefreshAt(): ?Carbon
    {
        return app(FinancePolicySettings::class)->getRebuildingLastEstimateRefreshAt();
    }

    public static function setRebuildingLastEstimateRefreshAt(Carbon $timestamp): void
    {
        app(FinancePolicySettings::class)->setRebuildingLastEstimateRefreshAt($timestamp);
    }

    public static function isDiscordVerificationRequired(): bool
    {
        return app(DiscordSettings::class)->isVerificationRequired();
    }

    public static function setDiscordVerificationRequired(bool $required): void
    {
        app(DiscordSettings::class)->setVerificationRequired($required);
    }

    public static function areDiscordPrivateNotificationsEnabled(): bool
    {
        return app(DiscordSettings::class)->arePrivateNotificationsEnabled();
    }

    public static function setDiscordPrivateNotificationsEnabled(bool $enabled): void
    {
        app(DiscordSettings::class)->setPrivateNotificationsEnabled($enabled);
    }

    public static function isMfaRequiredForAllUsers(): bool
    {
        return app(SecurityRetentionSettings::class)->isMfaRequiredForAllUsers();
    }

    public static function setMfaRequiredForAllUsers(bool $required): void
    {
        app(SecurityRetentionSettings::class)->setMfaRequiredForAllUsers($required);
    }

    public static function isMfaRequiredForAdmins(): bool
    {
        return app(SecurityRetentionSettings::class)->isMfaRequiredForAdmins();
    }

    public static function setMfaRequiredForAdmins(bool $required): void
    {
        app(SecurityRetentionSettings::class)->setMfaRequiredForAdmins($required);
    }

    public static function isAutoWithdrawEnabled(): bool
    {
        return app(FinancePolicySettings::class)->isAutoWithdrawEnabled();
    }

    public static function isBackupsEnabled(): bool
    {
        return app(SecurityRetentionSettings::class)->isBackupsEnabled();
    }

    public static function setAutoWithdrawEnabled(bool $enabled): void
    {
        app(FinancePolicySettings::class)->setAutoWithdrawEnabled($enabled);
    }

    public static function setBackupsEnabled(bool $enabled): void
    {
        app(SecurityRetentionSettings::class)->setBackupsEnabled($enabled);
    }

    public static function isLoanPaymentsEnabled(): bool
    {
        return app(FinancePolicySettings::class)->isLoanPaymentsEnabled();
    }

    public static function isLoanApplicationsEnabled(): bool
    {
        return app(FinancePolicySettings::class)->isLoanApplicationsEnabled();
    }

    public static function getDefaultLoanInterestRate(): float
    {
        return app(FinancePolicySettings::class)->getDefaultLoanInterestRate();
    }

    public static function setDefaultLoanInterestRate(float $rate): void
    {
        app(FinancePolicySettings::class)->setDefaultLoanInterestRate($rate);
    }

    public static function setLoanPaymentsEnabled(bool $enabled): void
    {
        app(FinancePolicySettings::class)->setLoanPaymentsEnabled($enabled);
    }

    public static function setLoanApplicationsEnabled(bool $enabled): void
    {
        app(FinancePolicySettings::class)->setLoanApplicationsEnabled($enabled);
    }

    public static function isGrantApprovalsEnabled(): bool
    {
        return app(FinancePolicySettings::class)->isGrantApprovalsEnabled();
    }

    public static function setGrantApprovalsEnabled(bool $enabled): void
    {
        app(FinancePolicySettings::class)->setGrantApprovalsEnabled($enabled);
    }

    public static function getAuditLogRetentionDays(): int
    {
        return app(SecurityRetentionSettings::class)->getAuditLogRetentionDays();
    }

    public static function setAuditLogRetentionDays(int $days): void
    {
        app(SecurityRetentionSettings::class)->setAuditLogRetentionDays($days);
    }

    public static function isUserInactivityAutoDisableEnabled(): bool
    {
        return app(SecurityRetentionSettings::class)->isUserInactivityAutoDisableEnabled();
    }

    public static function setUserInactivityAutoDisableEnabled(bool $enabled): void
    {
        app(SecurityRetentionSettings::class)->setUserInactivityAutoDisableEnabled($enabled);
    }

    public static function getUserInactivityAutoDisableDays(): int
    {
        return app(SecurityRetentionSettings::class)->getUserInactivityAutoDisableDays();
    }

    public static function setUserInactivityAutoDisableDays(int $days): void
    {
        app(SecurityRetentionSettings::class)->setUserInactivityAutoDisableDays($days);
    }

    public static function getFaviconPath(): ?string
    {
        return app(PublicSiteSettings::class)->getFaviconPath();
    }

    public static function setFaviconPath(?string $path): void
    {
        app(PublicSiteSettings::class)->setFaviconPath($path);
    }

    public static function getLoanPaymentsPausedAt(): ?Carbon
    {
        return app(FinancePolicySettings::class)->getLoanPaymentsPausedAt();
    }

    public static function setLoanPaymentsPausedAt(?Carbon $timestamp): void
    {
        app(FinancePolicySettings::class)->setLoanPaymentsPausedAt($timestamp);
    }

    public static function getTopRaidable(): int
    {
        return app(DataSyncSettings::class)->getTopRaidable();
    }

    public static function setTopRaidable(int $topN): void
    {
        app(DataSyncSettings::class)->setTopRaidable($topN);
    }

    public static function getRaidActivityCityThreshold(): int
    {
        return app(DataSyncSettings::class)->getRaidActivityCityThreshold();
    }

    public static function setRaidActivityCityThreshold(int $cityThreshold): void
    {
        app(DataSyncSettings::class)->setRaidActivityCityThreshold($cityThreshold);
    }

    public static function getRaidMinimumInactiveTurns(): int
    {
        return app(DataSyncSettings::class)->getRaidMinimumInactiveTurns();
    }

    public static function setRaidMinimumInactiveTurns(int $inactiveTurns): void
    {
        app(DataSyncSettings::class)->setRaidMinimumInactiveTurns($inactiveTurns);
    }

    public static function getDirectDepositId(): int
    {
        return app(FinancePolicySettings::class)->getDirectDepositId();
    }

    public static function setDirectDepositId(int $DDTaxID): void
    {
        app(FinancePolicySettings::class)->setDirectDepositId($DDTaxID);
    }

    public static function getDirectDepositFallbackId(): int
    {
        return app(FinancePolicySettings::class)->getDirectDepositFallbackId();
    }

    public static function setDirectDepositFallbackId(int $DDTaxID): void
    {
        app(FinancePolicySettings::class)->setDirectDepositFallbackId($DDTaxID);
    }

    public static function isDirectDepositEnabled(): bool
    {
        return app(FinancePolicySettings::class)->isDirectDepositEnabled();
    }

    public static function getGrowthCirclesTaxId(): int
    {
        return app(FinancePolicySettings::class)->getGrowthCirclesTaxId();
    }

    public static function setGrowthCirclesTaxId(int $taxId): void
    {
        app(FinancePolicySettings::class)->setGrowthCirclesTaxId($taxId);
    }

    public static function getGrowthCirclesFallbackTaxId(): int
    {
        return app(FinancePolicySettings::class)->getGrowthCirclesFallbackTaxId();
    }

    public static function setGrowthCirclesFallbackTaxId(int $taxId): void
    {
        app(FinancePolicySettings::class)->setGrowthCirclesFallbackTaxId($taxId);
    }

    public static function isGrowthCirclesEnabled(): bool
    {
        return app(FinancePolicySettings::class)->isGrowthCirclesEnabled();
    }

    public static function isInactivityModeEnabled(): bool
    {
        return app(InactivitySettings::class)->isEnabled();
    }

    public static function setInactivityModeEnabled(bool $enabled): void
    {
        app(InactivitySettings::class)->setEnabled($enabled);
    }

    public static function getInactivityThresholdHours(): int
    {
        return app(InactivitySettings::class)->getThresholdHours();
    }

    public static function setInactivityThresholdHours(int $hours): void
    {
        app(InactivitySettings::class)->setThresholdHours($hours);
    }

    /**
     * @return array<int, string>
     */
    public static function getInactivityActions(): array
    {
        return app(InactivitySettings::class)->getActions();
    }

    /**
     * @param  array<int, string>  $actions
     */
    public static function setInactivityActions(array $actions): void
    {
        app(InactivitySettings::class)->setActions($actions);
    }

    public static function getInactivityCooldownHours(): int
    {
        return app(InactivitySettings::class)->getCooldownHours();
    }

    public static function setInactivityCooldownHours(int $hours): void
    {
        app(InactivitySettings::class)->setCooldownHours($hours);
    }

    public static function getInactivityDiscordChannelId(): string
    {
        return app(InactivitySettings::class)->getDiscordChannelId();
    }

    public static function setInactivityDiscordChannelId(?string $channelId): void
    {
        app(InactivitySettings::class)->setDiscordChannelId($channelId);
    }

    public static function isInactivityRepeatNotificationsEnabled(): bool
    {
        return app(InactivitySettings::class)->areRepeatNotificationsEnabled();
    }

    public static function setInactivityRepeatNotificationsEnabled(bool $enabled): void
    {
        app(InactivitySettings::class)->setRepeatNotificationsEnabled($enabled);
    }

    public static function getDiscordWarAlertChannelId(): string
    {
        return app(DiscordSettings::class)->getWarAlertChannelId();
    }

    public static function setDiscordWarAlertChannelId(?string $channelId): void
    {
        app(DiscordSettings::class)->setWarAlertChannelId($channelId);
    }

    public static function getDiscordCityTierBucketSize(): int
    {
        return app(DiscordSettings::class)->getCityTierBucketSize();
    }

    public static function setDiscordCityTierBucketSize(int $bucketSize): void
    {
        app(DiscordSettings::class)->setCityTierBucketSize($bucketSize);
    }

    public static function getDiscordWarRoomForumId(): string
    {
        return app(DiscordSettings::class)->getWarRoomForumId();
    }

    public static function setDiscordWarRoomForumId(?string $channelId): void
    {
        app(DiscordSettings::class)->setWarRoomForumId($channelId);
    }

    public static function getDiscordWarRoomDefenseRoleId(): string
    {
        return app(DiscordSettings::class)->getWarRoomDefenseRoleId();
    }

    public static function setDiscordWarRoomDefenseRoleId(?string $roleId): void
    {
        app(DiscordSettings::class)->setWarRoomDefenseRoleId($roleId);
    }

    public static function isWarCounterAutoCreationEnabled(): bool
    {
        return app(DiscordSettings::class)->isWarCounterAutoCreationEnabled();
    }

    public static function setWarCounterAutoCreationEnabled(bool $enabled): void
    {
        app(DiscordSettings::class)->setWarCounterAutoCreationEnabled($enabled);
    }

    public static function getDiscordAllianceDepartureChannelId(): string
    {
        return app(DiscordSettings::class)->getAllianceDepartureChannelId();
    }

    public static function setDiscordAllianceDepartureChannelId(?string $channelId): void
    {
        app(DiscordSettings::class)->setAllianceDepartureChannelId($channelId);
    }

    public static function isDiscordWarAlertEnabled(): bool
    {
        return app(DiscordSettings::class)->isWarAlertEnabled();
    }

    public static function setDiscordWarAlertEnabled(bool $enabled): void
    {
        app(DiscordSettings::class)->setWarAlertEnabled($enabled);
    }

    public static function isDiscordAllianceDepartureEnabled(): bool
    {
        return app(DiscordSettings::class)->isAllianceDepartureEnabled();
    }

    public static function setDiscordAllianceDepartureEnabled(bool $enabled): void
    {
        app(DiscordSettings::class)->setAllianceDepartureEnabled($enabled);
    }

    public static function isBeigeAlertsEnabled(): bool
    {
        return app(DiscordSettings::class)->isBeigeAlertsEnabled();
    }

    public static function setBeigeAlertsEnabled(bool $enabled): void
    {
        app(DiscordSettings::class)->setBeigeAlertsEnabled($enabled);
    }

    public static function getBeigeAlertsDiscordChannelId(): string
    {
        return app(DiscordSettings::class)->getBeigeAlertsChannelId();
    }

    public static function setBeigeAlertsDiscordChannelId(?string $channelId): void
    {
        app(DiscordSettings::class)->setBeigeAlertsChannelId($channelId);
    }

    public static function getLastNationSyncBatchId(): string
    {
        return app(DataSyncSettings::class)->getLastNationSyncBatchId();
    }

    public static function setLastNationSyncBatchId(string $batchId): void
    {
        app(DataSyncSettings::class)->setLastNationSyncBatchId($batchId);
    }

    public static function getLastManualNationSyncBatchId(): string
    {
        return app(DataSyncSettings::class)->getLastManualNationSyncBatchId();
    }

    public static function setLastManualNationSyncBatchId(string $batchId): void
    {
        app(DataSyncSettings::class)->setLastManualNationSyncBatchId($batchId);
    }

    public static function getLastRollingNationSyncBatchId(): string
    {
        return app(DataSyncSettings::class)->getLastRollingNationSyncBatchId();
    }

    public static function setLastRollingNationSyncBatchId(string $batchId): void
    {
        app(DataSyncSettings::class)->setLastRollingNationSyncBatchId($batchId);
    }

    public static function getLastAllianceSyncBatchId(): string
    {
        return app(DataSyncSettings::class)->getLastAllianceSyncBatchId();
    }

    public static function setLastAllianceSyncBatchId(string $batchId): void
    {
        app(DataSyncSettings::class)->setLastAllianceSyncBatchId($batchId);
    }

    public static function getLastWarSyncBatchId(): string
    {
        return app(DataSyncSettings::class)->getLastWarSyncBatchId();
    }

    public static function setLastWarSyncBatchId(string $batchId): void
    {
        app(DataSyncSettings::class)->setLastWarSyncBatchId($batchId);
    }

    public static function getMMRAssistantEnabled(): bool
    {
        return app(MilitaryReadinessSettings::class)->isAssistantEnabled();
    }

    public static function setMMRAssistantEnabled(bool $enabled): void
    {
        app(MilitaryReadinessSettings::class)->setAssistantEnabled($enabled);
    }

    public static function getMMRResourceWeights(): array
    {
        return app(MilitaryReadinessSettings::class)->getResourceWeights();
    }

    public static function setMMRResourceWeights(array $weights): void
    {
        app(MilitaryReadinessSettings::class)->setResourceWeights($weights);
    }

    public static function isApplicationsEnabled(): bool
    {
        return app(ApplicationSettings::class)->isEnabled();
    }

    public static function setApplicationsEnabled(bool $enabled): void
    {
        app(ApplicationSettings::class)->setEnabled($enabled);
    }

    public static function getApplicationsApprovedPositionId(): int
    {
        return app(ApplicationSettings::class)->getApprovedPositionId();
    }

    public static function setApplicationsApprovedPositionId(int $positionId): void
    {
        app(ApplicationSettings::class)->setApprovedPositionId($positionId);
    }

    public static function getApplicationsDiscordApplicantRoleId(): string
    {
        return app(ApplicationSettings::class)->getDiscordApplicantRoleId();
    }

    public static function setApplicationsDiscordApplicantRoleId(?string $roleId): void
    {
        app(ApplicationSettings::class)->setDiscordApplicantRoleId($roleId);
    }

    public static function getApplicationsDiscordIaRoleId(): string
    {
        return app(ApplicationSettings::class)->getDiscordIaRoleId();
    }

    public static function setApplicationsDiscordIaRoleId(?string $roleId): void
    {
        app(ApplicationSettings::class)->setDiscordIaRoleId($roleId);
    }

    public static function getApplicationsDiscordMemberRoleId(): string
    {
        return app(ApplicationSettings::class)->getDiscordMemberRoleId();
    }

    public static function setApplicationsDiscordMemberRoleId(?string $roleId): void
    {
        app(ApplicationSettings::class)->setDiscordMemberRoleId($roleId);
    }

    public static function getApplicationsDiscordInterviewCategoryId(): string
    {
        return app(ApplicationSettings::class)->getDiscordInterviewCategoryId();
    }

    public static function setApplicationsDiscordInterviewCategoryId(?string $categoryId): void
    {
        app(ApplicationSettings::class)->setDiscordInterviewCategoryId($categoryId);
    }

    public static function getApplicationsApprovalAnnouncementChannelId(): string
    {
        return app(ApplicationSettings::class)->getApprovalAnnouncementChannelId();
    }

    public static function setApplicationsApprovalAnnouncementChannelId(?string $channelId): void
    {
        app(ApplicationSettings::class)->setApprovalAnnouncementChannelId($channelId);
    }

    public static function getApplicationsApprovalMessageTemplate(): string
    {
        return app(ApplicationSettings::class)->getApprovalMessageTemplate();
    }

    public static function setApplicationsApprovalMessageTemplate(string $template): void
    {
        app(ApplicationSettings::class)->setApprovalMessageTemplate($template);
    }

    public static function getWithdrawMaxDailyCount(): int
    {
        return app(FinancePolicySettings::class)->getWithdrawMaxDailyCount();
    }

    public static function setWithdrawMaxDailyCount(int $count): void
    {
        app(FinancePolicySettings::class)->setWithdrawMaxDailyCount($count);
    }

    public static function isRecruitmentEnabled(): bool
    {
        return app(RecruitmentSettings::class)->isEnabled();
    }

    public static function setRecruitmentEnabled(bool $enabled): void
    {
        app(RecruitmentSettings::class)->setEnabled($enabled);
    }

    public static function isRecruitmentFollowUpEnabled(): bool
    {
        return app(RecruitmentSettings::class)->isFollowUpEnabled();
    }

    public static function setRecruitmentFollowUpEnabled(bool $enabled): void
    {
        app(RecruitmentSettings::class)->setFollowUpEnabled($enabled);
    }

    public static function getRecruitmentPrimarySubject(): string
    {
        return app(RecruitmentSettings::class)->getPrimarySubject();
    }

    public static function setRecruitmentPrimarySubject(string $subject): void
    {
        app(RecruitmentSettings::class)->setPrimarySubject($subject);
    }

    public static function getRecruitmentPrimaryMessage(): string
    {
        return app(RecruitmentSettings::class)->getPrimaryMessage();
    }

    public static function setRecruitmentPrimaryMessage(string $message): void
    {
        app(RecruitmentSettings::class)->setPrimaryMessage($message);
    }

    public static function getRecruitmentFollowUpSubject(): string
    {
        return app(RecruitmentSettings::class)->getFollowUpSubject();
    }

    public static function setRecruitmentFollowUpSubject(string $subject): void
    {
        app(RecruitmentSettings::class)->setFollowUpSubject($subject);
    }

    public static function getRecruitmentFollowUpMessage(): string
    {
        return app(RecruitmentSettings::class)->getFollowUpMessage();
    }

    public static function setRecruitmentFollowUpMessage(string $message): void
    {
        app(RecruitmentSettings::class)->setFollowUpMessage($message);
    }

    public static function getHomepageHeadline(string $allianceName): string
    {
        return app(PublicSiteSettings::class)->getHomepageHeadline($allianceName);
    }

    public static function setHomepageHeadline(string $headline): void
    {
        app(PublicSiteSettings::class)->setHomepageHeadline($headline);
    }

    public static function getHomepageTagline(string $allianceName): string
    {
        return app(PublicSiteSettings::class)->getHomepageTagline($allianceName);
    }

    public static function setHomepageTagline(string $tagline): void
    {
        app(PublicSiteSettings::class)->setHomepageTagline($tagline);
    }

    public static function getHomepageAbout(string $allianceName): string
    {
        return app(PublicSiteSettings::class)->getHomepageAbout($allianceName);
    }

    public static function setHomepageAbout(string $about): void
    {
        app(PublicSiteSettings::class)->setHomepageAbout($about);
    }

    public static function getHomepageHighlights(): array
    {
        return app(PublicSiteSettings::class)->getHomepageHighlights();
    }

    public static function setHomepageHighlights(array $highlights): void
    {
        app(PublicSiteSettings::class)->setHomepageHighlights($highlights);
    }

    public static function getHomepageStatsIntro(): string
    {
        return app(PublicSiteSettings::class)->getHomepageStatsIntro();
    }

    public static function setHomepageStatsIntro(string $intro): void
    {
        app(PublicSiteSettings::class)->setHomepageStatsIntro($intro);
    }

    public static function getHomepageClosingText(string $allianceName): string
    {
        return app(PublicSiteSettings::class)->getHomepageClosingText($allianceName);
    }

    public static function setHomepageClosingText(string $text): void
    {
        app(PublicSiteSettings::class)->setHomepageClosingText($text);
    }

    public static function getHomepageHeroBadge(): string
    {
        return app(PublicSiteSettings::class)->getHomepageHeroBadge();
    }

    public static function setHomepageHeroBadge(string $badge): void
    {
        app(PublicSiteSettings::class)->setHomepageHeroBadge($badge);
    }

    public static function getHomepageCtaLabel(): string
    {
        return app(PublicSiteSettings::class)->getHomepageCtaLabel();
    }

    public static function setHomepageCtaLabel(string $label): void
    {
        app(PublicSiteSettings::class)->setHomepageCtaLabel($label);
    }

    protected static function getRecruitmentMessage(string $type, string $default): string
    {
        return app(RecruitmentSettings::class)->getMessage($type, $default);
    }

    protected static function setRecruitmentMessage(string $type, string $message): void
    {
        app(RecruitmentSettings::class)->setMessage($type, $message);
    }

    protected static function getStringSetting(string $key, string $default): string
    {
        return app(SettingValueStore::class)->getString($key, $default);
    }

    protected static function getStringSettingWithoutPersisting(string $key, string $default): string
    {
        return app(SettingValueStore::class)->getStringWithoutPersisting($key, $default);
    }
}
