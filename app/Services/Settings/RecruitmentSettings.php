<?php

namespace App\Services\Settings;

use App\Models\RecruitmentMessage;

class RecruitmentSettings
{
    private const SUBJECT_MAX_LENGTH = 50;

    public function __construct(private readonly SettingValueStore $settings) {}

    public function isEnabled(): bool
    {
        $value = $this->settings->get('recruitment_enabled');

        if (is_null($value)) {
            $this->setEnabled(false);

            return false;
        }

        return (bool) $value;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->settings->set('recruitment_enabled', $enabled ? 1 : 0);
    }

    public function isFollowUpEnabled(): bool
    {
        $value = $this->settings->get('recruitment_follow_up_enabled');

        if (is_null($value)) {
            $this->setFollowUpEnabled(false);

            return false;
        }

        return (bool) $value;
    }

    public function setFollowUpEnabled(bool $enabled): void
    {
        $this->settings->set('recruitment_follow_up_enabled', $enabled ? 1 : 0);
    }

    public function getPrimarySubject(): string
    {
        $value = $this->settings->get('recruitment_primary_subject');

        if (is_null($value) || $value === '') {
            $default = config('app.name').' Recruitment';
            $this->setPrimarySubject($default);

            return $this->normalizeSubject($default);
        }

        $subject = (string) $value;
        $normalized = $this->normalizeSubject($subject);

        if ($normalized !== $subject) {
            $this->settings->set('recruitment_primary_subject', $normalized);
        }

        return $normalized;
    }

    public function setPrimarySubject(string $subject): void
    {
        $this->settings->set('recruitment_primary_subject', $this->normalizeSubject($subject));
    }

    public function getPrimaryMessage(): string
    {
        $appName = config('app.name');
        $default = '<p>Welcome to Politics &amp; War!</p>'
            ."<p>The team at {$appName} would love to help you get started. "
            .'Join our Discord and we can walk you through your first steps.</p>';

        return $this->getMessage('primary', $default);
    }

    public function setPrimaryMessage(string $message): void
    {
        $this->setMessage('primary', $message);
    }

    public function getFollowUpSubject(): string
    {
        $value = $this->settings->get('recruitment_follow_up_subject');

        if (is_null($value) || $value === '') {
            $default = 'Checking in from '.config('app.name');
            $this->setFollowUpSubject($default);

            return $this->normalizeSubject($default);
        }

        $subject = (string) $value;
        $normalized = $this->normalizeSubject($subject);

        if ($normalized !== $subject) {
            $this->settings->set('recruitment_follow_up_subject', $normalized);
        }

        return $normalized;
    }

    public function setFollowUpSubject(string $subject): void
    {
        $this->settings->set('recruitment_follow_up_subject', $this->normalizeSubject($subject));
    }

    public function getFollowUpMessage(): string
    {
        $appName = config('app.name');
        $default = '<p>Hey there! Just following up to see how your nation is progressing.</p>'
            ."<p>If you are still looking for an alliance, we'd love to have you at {$appName}.</p>";

        return $this->getMessage('follow_up', $default);
    }

    public function setFollowUpMessage(string $message): void
    {
        $this->setMessage('follow_up', $message);
    }

    public function getMessage(string $type, string $default): string
    {
        $message = RecruitmentMessage::query()
            ->where('type', $type)
            ->value('message');

        if (is_null($message) || $message === '') {
            $this->setMessage($type, $default);

            return $default;
        }

        return (string) $message;
    }

    public function setMessage(string $type, string $message): void
    {
        RecruitmentMessage::query()->updateOrCreate(
            ['type' => $type],
            ['message' => $message],
        );
    }

    private function normalizeSubject(string $subject): string
    {
        return mb_substr(trim($subject), 0, self::SUBJECT_MAX_LENGTH);
    }
}
