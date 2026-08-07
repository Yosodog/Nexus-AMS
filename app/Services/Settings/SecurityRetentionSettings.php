<?php

namespace App\Services\Settings;

class SecurityRetentionSettings
{
    public function __construct(private readonly SettingValueStore $settings) {}

    public function isMfaRequiredForAllUsers(): bool
    {
        $value = $this->settings->get('require_mfa_all_users');

        if (is_null($value)) {
            $this->setMfaRequiredForAllUsers(false);

            return false;
        }

        return (bool) $value;
    }

    public function setMfaRequiredForAllUsers(bool $required): void
    {
        $this->settings->set('require_mfa_all_users', $required ? 1 : 0);
    }

    public function isMfaRequiredForAdmins(): bool
    {
        $value = $this->settings->get('require_mfa_admins');

        if (is_null($value)) {
            $this->setMfaRequiredForAdmins(false);

            return false;
        }

        return (bool) $value;
    }

    public function setMfaRequiredForAdmins(bool $required): void
    {
        $this->settings->set('require_mfa_admins', $required ? 1 : 0);
    }

    public function isBackupsEnabled(): bool
    {
        $value = $this->settings->get('backups_enabled');

        if (is_null($value)) {
            $this->setBackupsEnabled(false);

            return false;
        }

        return (bool) $value;
    }

    public function setBackupsEnabled(bool $enabled): void
    {
        $this->settings->set('backups_enabled', $enabled ? 1 : 0);
    }

    public function getAuditLogRetentionDays(): int
    {
        $value = $this->settings->get('audit_log_retention_days');

        if (is_null($value)) {
            $default = (int) config('audit.retention_days_default', 180);
            $this->setAuditLogRetentionDays($default);

            return $default;
        }

        return max(1, (int) $value);
    }

    public function setAuditLogRetentionDays(int $days): void
    {
        $this->settings->set('audit_log_retention_days', max(1, $days));
    }

    public function isUserInactivityAutoDisableEnabled(): bool
    {
        $value = $this->settings->get('user_inactivity_auto_disable_enabled');

        if (is_null($value)) {
            return false;
        }

        return (bool) $value;
    }

    public function setUserInactivityAutoDisableEnabled(bool $enabled): void
    {
        $this->settings->set('user_inactivity_auto_disable_enabled', $enabled ? 1 : 0);
    }

    public function getUserInactivityAutoDisableDays(): int
    {
        $value = $this->settings->get('user_inactivity_auto_disable_days');

        if (is_null($value)) {
            $this->setUserInactivityAutoDisableDays(90);

            return 90;
        }

        return max(1, (int) $value);
    }

    public function setUserInactivityAutoDisableDays(int $days): void
    {
        $this->settings->set('user_inactivity_auto_disable_days', max(1, $days));
    }
}
