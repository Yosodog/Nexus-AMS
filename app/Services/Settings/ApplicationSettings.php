<?php

namespace App\Services\Settings;

class ApplicationSettings
{
    public function __construct(private readonly SettingValueStore $settings) {}

    public function isEnabled(): bool
    {
        $value = $this->settings->get('applications_enabled');

        if (is_null($value)) {
            $this->setEnabled(false);

            return false;
        }

        return (bool) $value;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->settings->set('applications_enabled', $enabled ? 1 : 0);
    }

    public function getApprovedPositionId(): int
    {
        $value = $this->settings->get('applications_approved_position_id');

        if (is_null($value) || ! is_numeric($value)) {
            $this->setApprovedPositionId(0);

            return 0;
        }

        return (int) $value;
    }

    public function setApprovedPositionId(int $positionId): void
    {
        $this->settings->set('applications_approved_position_id', $positionId);
    }

    public function getDiscordApplicantRoleId(): string
    {
        return $this->settings->getString('applications_discord_applicant_role_id', '');
    }

    public function setDiscordApplicantRoleId(?string $roleId): void
    {
        $this->settings->set('applications_discord_applicant_role_id', $roleId ?? '');
    }

    public function getDiscordIaRoleId(): string
    {
        return $this->settings->getString('applications_discord_ia_role_id', '');
    }

    public function setDiscordIaRoleId(?string $roleId): void
    {
        $this->settings->set('applications_discord_ia_role_id', $roleId ?? '');
    }

    public function getDiscordMemberRoleId(): string
    {
        return $this->settings->getString('applications_discord_member_role_id', '');
    }

    public function setDiscordMemberRoleId(?string $roleId): void
    {
        $this->settings->set('applications_discord_member_role_id', $roleId ?? '');
    }

    public function getDiscordInterviewCategoryId(): string
    {
        return $this->settings->getString('applications_discord_interview_category_id', '');
    }

    public function setDiscordInterviewCategoryId(?string $categoryId): void
    {
        $this->settings->set('applications_discord_interview_category_id', $categoryId ?? '');
    }

    public function getApprovalAnnouncementChannelId(): string
    {
        return $this->settings->getString('applications_approval_announcement_channel_id', '');
    }

    public function setApprovalAnnouncementChannelId(?string $channelId): void
    {
        $this->settings->set('applications_approval_announcement_channel_id', $channelId ?? '');
    }

    public function getApprovalMessageTemplate(): string
    {
        $default = 'Welcome to the alliance! A new member has been approved.';

        return $this->settings->getString('applications_approval_message_template', $default);
    }

    public function setApprovalMessageTemplate(string $template): void
    {
        $this->settings->set('applications_approval_message_template', $template);
    }
}
