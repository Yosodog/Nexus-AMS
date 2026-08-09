<?php

namespace App\Services\Discord;

use App\Enums\ApplicationStatus;
use App\Enums\DiscordQueueStatus;
use App\Models\Application;
use App\Models\DiscordQueue;

final class ApplicationDiscordStatusProjection
{
    public const ACTION = 'APPLICATION_DISCORD_RECONCILE';

    /** @return array<string, mixed> */
    public function forMember(Application $application, ?DiscordQueue $queue = null): array
    {
        $queue = $this->matchingQueue($application, $queue);
        $reconciliationState = $this->reconciliationState($application, $queue);
        $issuesCount = collect($application->discord_reconcile_issues ?? [])
            ->filter(fn (mixed $issue): bool => is_string($issue) && trim($issue) !== '')
            ->unique()
            ->count();
        $channelId = $this->snowflake($application->discord_channel_id);
        $hasInvalidChannel = trim((string) $application->discord_channel_id) !== '' && $channelId === null;
        $channelState = $this->channelState(
            $application,
            $reconciliationState,
            $channelId,
            $hasInvalidChannel,
            $issuesCount,
        );
        $needsStaffAttention = $reconciliationState === 'attention'
            || $hasInvalidChannel
            || $issuesCount > 0;

        return [
            'channel_health' => array_filter([
                'state' => $channelState,
                'label' => $this->channelLabel($channelState),
                'channel_id' => $channelState === 'ready' ? $channelId : null,
            ], fn (mixed $value): bool => $value !== null),
            'progress' => [
                'facts' => $this->facts($application, $channelState, $reconciliationState, $needsStaffAttention),
                'blockers' => $this->blockers(
                    $application,
                    $channelState,
                    $needsStaffAttention,
                ),
                'next_action' => $this->nextAction($application, $channelState, $needsStaffAttention),
            ],
            'reconciliation' => [
                'state' => $reconciliationState,
                'label' => $this->reconciliationLabel($reconciliationState),
                'revision' => max(0, (int) $application->discord_reconcile_revision),
                'issues_count' => $issuesCount,
                'updated_at' => ($queue?->updated_at ?? $application->updated_at)->toIso8601String(),
            ],
        ];
    }

    private function matchingQueue(Application $application, ?DiscordQueue $queue): ?DiscordQueue
    {
        if (! $queue
            || ! is_string($application->discord_reconcile_queue_id)
            || ! hash_equals($application->discord_reconcile_queue_id, (string) $queue->getKey())
            || $queue->action !== self::ACTION) {
            return null;
        }

        $bindings = [
            'discord_connection_id' => 'connection_id',
            'discord_connection_generation' => 'connection_generation',
            'discord_application_id' => 'application_id',
            'discord_guild_id' => 'guild_id',
        ];

        foreach ($bindings as $applicationField => $queueField) {
            $expected = $application->{$applicationField};

            if ($expected !== null && ! hash_equals((string) $expected, (string) $queue->{$queueField})) {
                return null;
            }
        }

        return $queue;
    }

    private function reconciliationState(Application $application, ?DiscordQueue $queue): string
    {
        if (! $queue) {
            return (int) $application->discord_reconcile_revision > 0 ? 'attention' : 'not_requested';
        }

        $status = $queue->status instanceof DiscordQueueStatus
            ? $queue->status
            : DiscordQueueStatus::tryFrom((string) $queue->status);

        return match ($status) {
            DiscordQueueStatus::Pending => 'queued',
            DiscordQueueStatus::Processing => 'in_progress',
            DiscordQueueStatus::Complete => 'complete',
            DiscordQueueStatus::Failed, null => 'attention',
        };
    }

    private function channelState(
        Application $application,
        string $reconciliationState,
        ?string $channelId,
        bool $hasInvalidChannel,
        int $issuesCount,
    ): string {
        if ($application->status !== ApplicationStatus::Pending) {
            return match (true) {
                $reconciliationState === 'complete' => 'not_required',
                in_array($reconciliationState, ['queued', 'in_progress'], true) => 'cleanup_pending',
                $channelId !== null, $hasInvalidChannel, $issuesCount > 0, $reconciliationState === 'attention' => 'attention',
                default => 'unknown',
            };
        }

        return match (true) {
            $channelId !== null => 'ready',
            $hasInvalidChannel, $issuesCount > 0, $reconciliationState === 'attention' => 'attention',
            in_array($reconciliationState, ['queued', 'in_progress'], true) => 'preparing',
            default => 'unknown',
        };
    }

    /** @return list<array{key: string, label: string, complete: bool}> */
    private function facts(
        Application $application,
        string $channelState,
        string $reconciliationState,
        bool $needsStaffAttention,
    ): array {
        $decisionRecorded = $application->status !== ApplicationStatus::Pending;

        return [
            [
                'key' => 'submitted',
                'label' => 'Application submitted to Nexus',
                'complete' => true,
            ],
            [
                'key' => 'interview_channel',
                'label' => $decisionRecorded
                    ? 'Private interview stage closed'
                    : 'Private interview channel ready',
                'complete' => $decisionRecorded || $channelState === 'ready',
            ],
            [
                'key' => 'staff_decision',
                'label' => 'Staff decision recorded',
                'complete' => $decisionRecorded,
            ],
            [
                'key' => 'discord_follow_up',
                'label' => 'Discord follow-up reconciled',
                'complete' => $reconciliationState === 'complete' && ! $needsStaffAttention,
            ],
        ];
    }

    /** @return list<array{code: string, message: string, user_action: array{label: string, deep_link_path: string}}> */
    private function blockers(
        Application $application,
        string $channelState,
        bool $needsStaffAttention,
    ): array {
        $path = route('apply.show', ['application' => $application->id], absolute: false);
        if ($needsStaffAttention) {
            return [[
                'code' => 'discord_follow_up_needs_staff',
                'message' => 'Nexus could not confirm all Discord application follow-up. Application staff need to review the server integration.',
                'user_action' => [
                    'label' => 'Continue your application in Nexus',
                    'deep_link_path' => $path,
                ],
            ]];
        }
        if ($application->status === ApplicationStatus::Pending && $channelState === 'unknown') {
            return [[
                'code' => 'discord_setup_unconfirmed',
                'message' => 'Nexus has not yet confirmed a private Discord interview channel for this application.',
                'user_action' => [
                    'label' => 'Continue your application in Nexus',
                    'deep_link_path' => $path,
                ],
            ]];
        }

        return [];
    }

    /** @return array{label: string, deep_link_path: string} */
    private function nextAction(Application $application, string $channelState, bool $needsStaffAttention): array
    {
        if ($application->status === ApplicationStatus::Approved) {
            return [
                'label' => 'Open your Nexus dashboard',
                'deep_link_path' => route('user.dashboard', absolute: false),
            ];
        }

        $label = match (true) {
            $needsStaffAttention => 'Continue in Nexus and contact application staff',
            $application->status !== ApplicationStatus::Pending => 'Review your application in Nexus',
            $channelState === 'ready' => 'Continue in your private interview channel',
            $channelState === 'preparing' => 'Wait for Discord setup or continue in Nexus',
            default => 'Continue your application in Nexus',
        };

        return [
            'label' => $label,
            'deep_link_path' => route('apply.show', ['application' => $application->id], absolute: false),
        ];
    }

    private function channelLabel(string $state): string
    {
        return match ($state) {
            'ready' => 'Private interview channel is ready.',
            'preparing' => 'Private interview channel setup is in progress.',
            'cleanup_pending' => 'Discord channel cleanup is in progress.',
            'not_required' => 'No application interview channel is required now.',
            'attention' => 'Discord channel setup needs staff attention.',
            default => 'Discord channel status has not been confirmed.',
        };
    }

    private function reconciliationLabel(string $state): string
    {
        return match ($state) {
            'queued' => 'Discord follow-up is queued.',
            'in_progress' => 'Discord follow-up is in progress.',
            'complete' => 'Discord follow-up completed.',
            'attention' => 'Discord follow-up needs staff attention.',
            default => 'Discord follow-up has not been requested.',
        };
    }

    private function snowflake(?string $value): ?string
    {
        $value = trim((string) $value);

        return preg_match('/^\d{17,20}$/', $value) === 1 ? $value : null;
    }
}
