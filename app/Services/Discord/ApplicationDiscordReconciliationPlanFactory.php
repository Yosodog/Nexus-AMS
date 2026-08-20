<?php

namespace App\Services\Discord;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Services\SettingService;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;

final class ApplicationDiscordReconciliationPlanFactory
{
    /**
     * @return array{
     *     payload: array<string, mixed>,
     *     issues: list<string>,
     *     desired_hash: string
     * }
     *
     * @throws JsonException
     */
    public function make(
        Application $application,
        string $discordApplicationId,
        string $guildId,
        string $connectionId,
        int $connectionGeneration,
        int $revision,
        bool $includeOneTimeMessages = true,
    ): array {
        $discordApplicationId = $this->requireSnowflake($discordApplicationId, 'Discord application ID');
        $guildId = $this->requireSnowflake($guildId, 'Discord guild ID');
        $connectionId = strtolower(trim($connectionId));

        if (! Str::isUuid($connectionId)) {
            throw new InvalidArgumentException('A valid Discord connection ID is required.');
        }
        if ($connectionGeneration < 1 || $revision < 1) {
            throw new InvalidArgumentException('Discord connection and application revisions must be positive.');
        }
        if (! $application->exists || $application->id < 1 || $application->nation_id < 1) {
            throw new InvalidArgumentException('A persisted application with a valid nation is required.');
        }

        $discordUserId = $this->requireSnowflake($application->discord_user_id, 'Applicant Discord user ID');
        $issues = [];
        $state = strtolower($application->status->value);
        $channel = $this->channelPlan($application, $state, $issues, $includeOneTimeMessages);
        $roles = $this->rolePlan($state, $issues);
        $notifications = $this->notificationPlan($state, $issues, $includeOneTimeMessages);
        $applicationState = [
            'id' => $application->id,
            'state' => $state,
            'discord_user_id' => $discordUserId,
            'nation_id' => $application->nation_id,
        ];
        $desired = [
            'channel' => $channel,
            'roles' => $roles,
            'notifications' => $notifications,
        ];
        $payload = [
            'contract_version' => 1,
            'installation' => [
                'application_id' => $discordApplicationId,
                'guild_id' => $guildId,
                'connection_id' => $connectionId,
                'generation' => $connectionGeneration,
            ],
            'application' => [
                ...$applicationState,
                'revision' => $revision,
            ],
            'desired' => $desired,
        ];

        return [
            'payload' => $payload,
            'issues' => array_values(array_unique($issues)),
            'desired_hash' => hash('sha256', json_encode(
                ['application' => $applicationState, 'desired' => $desired],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            )),
        ];
    }

    /**
     * @param  list<string>  $issues
     * @return array<string, mixed>
     */
    private function channelPlan(
        Application $application,
        string $state,
        array &$issues,
        bool $includeOneTimeMessages,
    ): array {
        $topic = "nexus-application:{$application->id};nation:{$application->nation_id} | "
            ."https://politicsandwar.com/nation/id={$application->nation_id}";
        $channelId = $this->optionalSnowflake($application->discord_channel_id, 'application_channel_invalid', $issues);

        if ($state !== strtolower(ApplicationStatus::Pending->value)) {
            return array_filter([
                'mode' => 'absent',
                'channel_id' => $channelId,
                'topic' => $topic,
                'intro_messages' => [],
            ], fn (mixed $value): bool => $value !== null);
        }

        $interviewerRoleId = $this->configuredSnowflake(
            SettingService::getApplicationsDiscordIaRoleId(),
            'interviewer_role_not_configured',
            $issues,
        );
        $categoryId = $this->optionalConfiguredSnowflake(
            SettingService::getApplicationsDiscordInterviewCategoryId(),
            'interview_category_invalid',
            $issues,
        );
        $leaderSlug = Str::slug($application->leader_name_snapshot) ?: 'applicant';
        $name = Str::limit(
            "app-{$application->id}-{$application->nation_id}-{$leaderSlug}",
            100,
            '',
        );

        return array_filter([
            'mode' => $interviewerRoleId === null ? 'unchanged' : 'ensure',
            'channel_id' => $channelId,
            'category_id' => $categoryId,
            'name' => $name,
            'topic' => $topic,
            'staff_role_ids' => $interviewerRoleId === null ? [] : [$interviewerRoleId],
            'intro_messages' => $interviewerRoleId !== null && $includeOneTimeMessages
                ? [[
                    'key' => 'application.submitted',
                    'content' => "Application #{$application->id} for nation #{$application->nation_id} is ready for interview. Continue in this private channel; staff will respond here.",
                ]]
                : [],
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  list<string>  $issues
     * @return array{add: list<string>, remove: list<string>}
     */
    private function rolePlan(string $state, array &$issues): array
    {
        $applicantRoleId = $this->configuredSnowflake(
            SettingService::getApplicationsDiscordApplicantRoleId(),
            'applicant_role_not_configured',
            $issues,
        );

        if ($state === strtolower(ApplicationStatus::Pending->value)) {
            return [
                'add' => $applicantRoleId === null ? [] : [$applicantRoleId],
                'remove' => [],
            ];
        }

        $add = [];
        if ($state === strtolower(ApplicationStatus::Approved->value)) {
            $memberRoleId = $this->configuredSnowflake(
                SettingService::getApplicationsDiscordMemberRoleId(),
                'member_role_not_configured',
                $issues,
            );
            if ($memberRoleId !== null) {
                $add[] = $memberRoleId;
            }
        }

        return [
            'add' => $add,
            'remove' => $applicantRoleId === null ? [] : [$applicantRoleId],
        ];
    }

    /**
     * @param  list<string>  $issues
     * @return list<array<string, mixed>>
     */
    private function notificationPlan(string $state, array &$issues, bool $includeOneTimeMessages): array
    {
        if ($state !== strtolower(ApplicationStatus::Approved->value) || ! $includeOneTimeMessages) {
            return [];
        }

        $rawChannelId = trim(SettingService::getApplicationsApprovalAnnouncementChannelId());
        $template = trim(SettingService::getApplicationsApprovalMessageTemplate());
        if ($rawChannelId === '' && $template === '') {
            return [];
        }

        $channelId = $this->optionalSnowflake($rawChannelId, 'approval_announcement_channel_invalid', $issues);
        if ($template === '' || ! $this->safeMessage($template)) {
            $issues[] = 'approval_announcement_template_invalid';
        }
        if ($channelId === null || $template === '' || ! $this->safeMessage($template)) {
            return [];
        }

        return [[
            'key' => 'application.approved',
            'destination' => [
                'type' => 'channel',
                'channel_id' => $channelId,
            ],
            'content' => $template,
        ]];
    }

    private function requireSnowflake(string $value, string $label): string
    {
        $value = trim($value);
        if (preg_match('/^\d{17,20}$/', $value) !== 1) {
            throw new InvalidArgumentException("{$label} is invalid.");
        }

        return $value;
    }

    /** @param list<string> $issues */
    private function configuredSnowflake(string $value, string $issue, array &$issues): ?string
    {
        $value = trim($value);
        if ($value === '' || preg_match('/^\d{17,20}$/', $value) !== 1) {
            $issues[] = $issue;

            return null;
        }

        return $value;
    }

    /** @param list<string> $issues */
    private function optionalConfiguredSnowflake(string $value, string $issue, array &$issues): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        return $this->optionalSnowflake($value, $issue, $issues);
    }

    /** @param list<string> $issues */
    private function optionalSnowflake(?string $value, string $issue, array &$issues): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^\d{17,20}$/', $value) !== 1) {
            $issues[] = $issue;

            return null;
        }

        return $value;
    }

    private function safeMessage(string $message): bool
    {
        return mb_strlen($message) <= 2000
            && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $message) !== 1
            && preg_match('/@(?:everyone|here)\b|<@!?\d{17,20}>|<@&\d{17,20}>|<a?:[a-z0-9_~-]+:\d{17,20}>/iu', $message) !== 1
            && preg_match('/\bassign\w*\b/iu', $message) !== 1;
    }
}
