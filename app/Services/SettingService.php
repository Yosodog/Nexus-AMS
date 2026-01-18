<?php

namespace App\Services;

use App\Models\RecruitmentMessage;
use App\Models\Setting;
use Illuminate\Support\Carbon;

class SettingService
{
    public static function getLastScannedBankRecordId(): int
    {
        $id = self::getValue('last_bank_record_id');

        if (is_null($id)) { // If the value does not exist, then we need to create it and just return 0
            self::setValue('last_bank_record_id', 0);

            return 0;
        }

        return $id;
    }

    public static function getValue(string $key): mixed
    {
        return Setting::where('key', $key)->value('value');
    }

    /**
     * Use this to set values for settings. It can also create new setting
     * values if necessary.
     */
    public static function setValue(string $key, mixed $value): void
    {
        Setting::updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );
    }

    public static function setLastScannedBankRecordId(int $id): void
    {
        self::setValue('last_bank_record_id', $id);
    }

    public static function getCityAverage(): ?float
    {
        $value = self::getValue('pw_city_average');

        return $value === null ? null : (float) $value;
    }

    public static function setCityAverage(float $average): void
    {
        self::setValue('pw_city_average', $average);
    }

    public static function getCityAverageUpdatedAt(): ?Carbon
    {
        $value = self::getValue('pw_city_average_updated_at');

        return $value ? Carbon::parse($value) : null;
    }

    public static function setCityAverageUpdatedAt(Carbon $timestamp): void
    {
        self::setValue('pw_city_average_updated_at', $timestamp->toIso8601String());
    }

    public static function isWarAidEnabled(): bool
    {
        $value = self::getValue('war_aid_enabled');

        if (is_null($value)) {
            self::setValue('war_aid_enabled', 0); // Default to disabled

            return false;
        }

        return (bool) $value;
    }

    public static function setWarAidEnabled(bool $enabled): void
    {
        self::setValue('war_aid_enabled', $enabled ? 1 : 0);
    }

    public static function isDiscordVerificationRequired(): bool
    {
        $value = self::getValue('require_discord_verification');

        if (is_null($value)) {
            self::setDiscordVerificationRequired(false);

            return false;
        }

        return (bool) $value;
    }

    public static function setDiscordVerificationRequired(bool $required): void
    {
        self::setValue('require_discord_verification', $required ? 1 : 0);
    }

    public static function isAutoWithdrawEnabled(): bool
    {
        $value = self::getValue('auto_withdraw_enabled');

        if (is_null($value)) {
            self::setAutoWithdrawEnabled(true);

            return true;
        }

        return (bool) $value;
    }

    public static function setAutoWithdrawEnabled(bool $enabled): void
    {
        self::setValue('auto_withdraw_enabled', $enabled ? 1 : 0);
    }

    public static function isLoanPaymentsEnabled(): bool
    {
        $value = self::getValue('loan_payments_enabled');

        if (is_null($value)) {
            self::setLoanPaymentsEnabled(true);

            return true;
        }

        return (bool) $value;
    }

    public static function setLoanPaymentsEnabled(bool $enabled): void
    {
        self::setValue('loan_payments_enabled', $enabled ? 1 : 0);
    }

    public static function getLoanPaymentsPausedAt(): ?Carbon
    {
        $value = self::getValue('loan_payments_paused_at');

        if (! is_string($value) || $value === '') {
            return null;
        }

        return Carbon::parse($value);
    }

    public static function setLoanPaymentsPausedAt(?Carbon $timestamp): void
    {
        self::setValue('loan_payments_paused_at', $timestamp ? $timestamp->toIso8601String() : '');
    }

    public static function getTopRaidable(): int
    {
        $value = self::getValue('raid_top_alliance_cap');

        if (is_null($value)) {
            self::setTopRaidable(40); // Default to 40

            return 40;
        }

        return (int) $value;
    }

    public static function setTopRaidable(int $topN): void
    {
        self::setValue('raid_top_alliance_cap', $topN);
    }

    public static function getDirectDepositId(): int
    {
        $value = self::getValue('dd_tax_id');

        if (is_null($value)) {
            self::setDirectDepositId(0); // Default to 0

            return 0;
        }

        return (int) $value;
    }

    public static function setDirectDepositId(int $DDTaxID): void
    {
        self::setValue('dd_tax_id', $DDTaxID);
    }

    public static function getDirectDepositFallbackId(): int
    {
        $value = self::getValue('dd_fallback_tax_id');

        if (is_null($value)) {
            self::setDirectDepositFallbackId(0); // Default to 0

            return 0;
        }

        return (int) $value;
    }

    public static function setDirectDepositFallbackId(int $DDTaxID): void
    {
        self::setValue('dd_fallback_tax_id', $DDTaxID);
    }

    public static function getDiscordWarAlertChannelId(): string
    {
        return (string) (self::getValue('discord_war_alert_channel_id') ?? '');
    }

    public static function setDiscordWarAlertChannelId(?string $channelId): void
    {
        self::setValue('discord_war_alert_channel_id', $channelId ?? '');
    }

    public static function getDiscordAllianceDepartureChannelId(): string
    {
        $channelId = self::getValue('discord_alliance_departure_channel_id');

        if (is_string($channelId) && $channelId !== '') {
            return $channelId;
        }

        return self::getDiscordWarAlertChannelId();
    }

    public static function setDiscordAllianceDepartureChannelId(?string $channelId): void
    {
        self::setValue('discord_alliance_departure_channel_id', $channelId ?? '');
    }

    public static function isDiscordWarAlertEnabled(): bool
    {
        return (bool) (self::getValue('discord_war_alert_enabled') ?? false);
    }

    public static function setDiscordWarAlertEnabled(bool $enabled): void
    {
        self::setValue('discord_war_alert_enabled', $enabled ? 1 : 0);
    }

    public static function isDiscordAllianceDepartureEnabled(): bool
    {
        $value = self::getValue('discord_alliance_departure_enabled');

        if (is_null($value)) {
            return self::isDiscordWarAlertEnabled();
        }

        return (bool) $value;
    }

    public static function setDiscordAllianceDepartureEnabled(bool $enabled): void
    {
        self::setValue('discord_alliance_departure_enabled', $enabled ? 1 : 0);
    }

    public static function getLastNationSyncBatchId(): string
    {
        return self::getLastManualNationSyncBatchId();
    }

    public static function setLastNationSyncBatchId(string $batchId): void
    {
        self::setLastManualNationSyncBatchId($batchId);
    }

    public static function getLastManualNationSyncBatchId(): string
    {
        $value = self::getValue('last_nation_sync_batch_id');

        if (is_null($value)) {
            self::setLastManualNationSyncBatchId('');

            return '';
        }

        return $value;
    }

    public static function setLastManualNationSyncBatchId(string $batchId): void
    {
        self::setValue('last_nation_sync_batch_id', $batchId);
    }

    public static function getLastRollingNationSyncBatchId(): string
    {
        $value = self::getValue('last_rolling_nation_sync_batch_id');

        if (is_null($value)) {
            self::setLastRollingNationSyncBatchId('');

            return '';
        }

        return $value;
    }

    public static function setLastRollingNationSyncBatchId(string $batchId): void
    {
        self::setValue('last_rolling_nation_sync_batch_id', $batchId);
    }

    public static function getLastAllianceSyncBatchId(): string
    {
        $value = self::getValue('last_alliance_sync_batch_id');

        if (is_null($value)) {
            self::setLastAllianceSyncBatchId('');

            return '';
        }

        return $value;
    }

    public static function setLastAllianceSyncBatchId(string $batchId): void
    {
        self::setValue('last_alliance_sync_batch_id', $batchId);
    }

    public static function getLastWarSyncBatchId(): string
    {
        $value = self::getValue('last_war_sync_batch_id');

        if (is_null($value)) {
            self::setLastWarSyncBatchId('');

            return '';
        }

        return $value;
    }

    public static function setLastWarSyncBatchId(string $batchId): void
    {
        self::setValue('last_war_sync_batch_id', $batchId);
    }

    public static function getMMRAssistantEnabled(): bool
    {
        $value = self::getValue('mmr_assistant_enabled');

        if (is_null($value)) {
            self::setMMRAssistantEnabled(false);

            return false;
        }

        return (bool) $value;
    }

    public static function setMMRAssistantEnabled(bool $enabled): void
    {
        self::setValue('mmr_assistant_enabled', (int) $enabled);
    }

    public static function getMMRResourceWeights(): array
    {
        $raw = self::getValue('mmr_resource_weights');

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);

            if (is_array($decoded)) {
                return collect($decoded)
                    ->map(fn ($weight) => (float) $weight)
                    ->toArray();
            }
        }

        return [];
    }

    public static function setMMRResourceWeights(array $weights): void
    {
        self::setValue('mmr_resource_weights', json_encode($weights));
    }

    public static function isApplicationsEnabled(): bool
    {
        $value = self::getValue('applications_enabled');

        if (is_null($value)) {
            self::setApplicationsEnabled(false);

            return false;
        }

        return (bool) $value;
    }

    public static function setApplicationsEnabled(bool $enabled): void
    {
        self::setValue('applications_enabled', $enabled ? 1 : 0);
    }

    public static function getApplicationsApprovedPositionId(): int
    {
        $value = self::getValue('applications_approved_position_id');

        if (is_null($value) || ! is_numeric($value)) {
            self::setApplicationsApprovedPositionId(0);

            return 0;
        }

        return (int) $value;
    }

    public static function setApplicationsApprovedPositionId(int $positionId): void
    {
        self::setValue('applications_approved_position_id', $positionId);
    }

    public static function getApplicationsDiscordApplicantRoleId(): string
    {
        return self::getStringSetting('applications_discord_applicant_role_id', '');
    }

    public static function setApplicationsDiscordApplicantRoleId(?string $roleId): void
    {
        self::setValue('applications_discord_applicant_role_id', $roleId ?? '');
    }

    public static function getApplicationsDiscordIaRoleId(): string
    {
        return self::getStringSetting('applications_discord_ia_role_id', '');
    }

    public static function setApplicationsDiscordIaRoleId(?string $roleId): void
    {
        self::setValue('applications_discord_ia_role_id', $roleId ?? '');
    }

    public static function getApplicationsDiscordMemberRoleId(): string
    {
        return self::getStringSetting('applications_discord_member_role_id', '');
    }

    public static function setApplicationsDiscordMemberRoleId(?string $roleId): void
    {
        self::setValue('applications_discord_member_role_id', $roleId ?? '');
    }

    public static function getApplicationsDiscordInterviewCategoryId(): string
    {
        return self::getStringSetting('applications_discord_interview_category_id', '');
    }

    public static function setApplicationsDiscordInterviewCategoryId(?string $categoryId): void
    {
        self::setValue('applications_discord_interview_category_id', $categoryId ?? '');
    }

    public static function getApplicationsApprovalAnnouncementChannelId(): string
    {
        return self::getStringSetting('applications_approval_announcement_channel_id', '');
    }

    public static function setApplicationsApprovalAnnouncementChannelId(?string $channelId): void
    {
        self::setValue('applications_approval_announcement_channel_id', $channelId ?? '');
    }

    public static function getApplicationsApprovalMessageTemplate(): string
    {
        $default = 'Welcome to the alliance! A new member has been approved.';

        return self::getStringSetting('applications_approval_message_template', $default);
    }

    public static function setApplicationsApprovalMessageTemplate(string $template): void
    {
        self::setValue('applications_approval_message_template', $template);
    }

    public static function getWithdrawMaxDailyCount(): int
    {
        $value = self::getValue('withdraw_max_daily_count');

        if (is_null($value)) {
            self::setWithdrawMaxDailyCount(0);

            return 0;
        }

        return (int) $value;
    }

    public static function setWithdrawMaxDailyCount(int $count): void
    {
        self::setValue('withdraw_max_daily_count', max(0, $count));
    }

    public static function isRecruitmentEnabled(): bool
    {
        $value = self::getValue('recruitment_enabled');

        if (is_null($value)) {
            self::setRecruitmentEnabled(false);

            return false;
        }

        return (bool) $value;
    }

    public static function setRecruitmentEnabled(bool $enabled): void
    {
        self::setValue('recruitment_enabled', $enabled ? 1 : 0);
    }

    public static function isRecruitmentFollowUpEnabled(): bool
    {
        $value = self::getValue('recruitment_follow_up_enabled');

        if (is_null($value)) {
            self::setRecruitmentFollowUpEnabled(false);

            return false;
        }

        return (bool) $value;
    }

    public static function setRecruitmentFollowUpEnabled(bool $enabled): void
    {
        self::setValue('recruitment_follow_up_enabled', $enabled ? 1 : 0);
    }

    public static function getRecruitmentPrimarySubject(): string
    {
        $value = self::getValue('recruitment_primary_subject');

        if (is_null($value) || $value === '') {
            $appName = config('app.name', 'Nexus');
            $default = $appName.' Recruitment';
            self::setRecruitmentPrimarySubject($default);

            return $default;
        }

        return (string) $value;
    }

    public static function setRecruitmentPrimarySubject(string $subject): void
    {
        self::setValue('recruitment_primary_subject', $subject);
    }

    public static function getRecruitmentPrimaryMessage(): string
    {
        $appName = config('app.name', 'Nexus');
        $default = '<p>Welcome to Politics &amp; War!</p>'
            ."<p>The team at {$appName} would love to help you get started. "
            .'Join our Discord and we can walk you through your first steps.</p>';

        return self::getRecruitmentMessage('primary', $default);
    }

    public static function setRecruitmentPrimaryMessage(string $message): void
    {
        self::setRecruitmentMessage('primary', $message);
    }

    public static function getRecruitmentFollowUpSubject(): string
    {
        $value = self::getValue('recruitment_follow_up_subject');

        if (is_null($value) || $value === '') {
            $appName = config('app.name', 'Nexus');
            $default = 'Checking in from '.$appName;
            self::setRecruitmentFollowUpSubject($default);

            return $default;
        }

        return (string) $value;
    }

    public static function setRecruitmentFollowUpSubject(string $subject): void
    {
        self::setValue('recruitment_follow_up_subject', $subject);
    }

    public static function getRecruitmentFollowUpMessage(): string
    {
        $appName = config('app.name', 'Nexus');
        $default = '<p>Hey there! Just following up to see how your nation is progressing.</p>'
            ."<p>If you are still looking for an alliance, we'd love to have you at {$appName}.</p>";

        return self::getRecruitmentMessage('follow_up', $default);
    }

    public static function setRecruitmentFollowUpMessage(string $message): void
    {
        self::setRecruitmentMessage('follow_up', $message);
    }

    public static function getHomepageHeadline(string $allianceName): string
    {
        $default = "Join {$allianceName}";

        return self::getStringSetting('home_headline', $default);
    }

    public static function setHomepageHeadline(string $headline): void
    {
        self::setValue('home_headline', $headline);
    }

    public static function getHomepageTagline(string $allianceName): string
    {
        $default = "{$allianceName} is growing — and we operate with clarity, fairness, and speed.";

        return self::getStringSetting('home_tagline', $default);
    }

    public static function setHomepageTagline(string $tagline): void
    {
        self::setValue('home_tagline', $tagline);
    }

    public static function getHomepageAbout(string $allianceName): string
    {
        $appName = config('app.name', 'Nexus AMS');
        $default = "{$allianceName} runs recruitment, economic programs, and defense with {$appName}. "
            .'Your experience stays transparent while leadership keeps operations secure.';

        return self::getStringSetting('home_about', $default);
    }

    public static function setHomepageAbout(string $about): void
    {
        self::setValue('home_about', $about);
    }

    public static function getHomepageHighlights(): array
    {
        $raw = self::getValue('home_highlights');

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);

            if (is_array($decoded)) {
                return collect($decoded)
                    ->map(fn ($item) => is_string($item) ? trim($item) : '')
                    ->filter()
                    ->values()
                    ->all();
            }
        }

        return [
            'Guest-friendly application with consistent questions.',
            'Clear grant and loan programs—no guesswork for members.',
            'Defense coordination tools without exposing sensitive data.',
        ];
    }

    public static function setHomepageHighlights(array $highlights): void
    {
        $cleaned = collect($highlights)
            ->map(fn ($item) => is_string($item) ? trim($item) : '')
            ->filter()
            ->values()
            ->all();

        self::setValue('home_highlights', json_encode($cleaned));
    }

    protected static function getRecruitmentMessage(string $type, string $default): string
    {
        $message = RecruitmentMessage::query()
            ->where('type', $type)
            ->value('message');

        if (is_null($message) || $message === '') {
            self::setRecruitmentMessage($type, $default);

            return $default;
        }

        return (string) $message;
    }

    protected static function setRecruitmentMessage(string $type, string $message): void
    {
        RecruitmentMessage::query()->updateOrCreate(
            ['type' => $type],
            ['message' => $message]
        );
    }

    protected static function getStringSetting(string $key, string $default): string
    {
        $value = self::getValue($key);

        if (is_null($value) || $value === '') {
            self::setValue($key, $default);

            return $default;
        }

        return (string) $value;
    }
}
