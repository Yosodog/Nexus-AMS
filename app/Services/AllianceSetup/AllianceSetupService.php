<?php

namespace App\Services\AllianceSetup;

use App\Enums\AllianceSetupStep;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Discord\PrivateNotificationService;
use App\Services\Settings\ApplicationSettings;
use App\Services\Settings\DiscordSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class AllianceSetupService
{
    public function __construct(
        private AllianceSetupStateStore $states,
        private AllianceSetupReadinessService $readiness,
        private DiscordSettings $discordSettings,
        private ApplicationSettings $applicationSettings,
        private PrivateNotificationService $privateNotifications,
        private AuditLogger $audit,
    ) {}

    public function acknowledgeIntro(User $actor, bool $start): void
    {
        $state = $this->states->acknowledgeIntro($actor->getKey(), $start);
        $this->audit->success('alliance_setup', $start ? 'setup_started' : 'setup_deferred', $actor, [
            'setup_version' => $state->version,
        ], $start ? 'Guided alliance setup started.' : 'Guided alliance setup deferred.');
    }

    public function start(User $actor): void
    {
        $state = $this->states->start($actor->getKey());
        $this->audit->success('alliance_setup', 'setup_started', $actor, [
            'setup_version' => $state->version,
            'manual' => true,
        ], 'Guided alliance setup started manually.');
    }

    public function reset(User $actor): void
    {
        $wasCorrupt = $this->states->read()->corrupt;
        $state = $this->states->reset($actor->getKey());
        $this->audit->success('alliance_setup', 'setup_metadata_reset', $actor, [
            'setup_version' => $state->version,
            'recovered_corrupt_state' => $wasCorrupt,
        ], 'Alliance setup metadata reset.');
    }

    public function continueFrom(AllianceSetupStep $step): void
    {
        $this->assertUsableState();
        $this->states->saveCurrentStep($step);
    }

    public function saveDiscord(User $actor, bool $configureNow, bool $verificationRequired, bool $privateNotifications): void
    {
        $this->assertUsableState();
        $snapshot = $this->readiness->snapshot($actor);

        if ($configureNow && ! $snapshot['context']['discord']['connected']) {
            throw ValidationException::withMessages([
                'configure_now' => 'An accepted Discord connection is required before these preferences can be enabled.',
            ]);
        }

        if (! $configureNow) {
            $verificationRequired = false;
            $privateNotifications = false;
        }

        $suppressedCount = DB::transaction(function () use ($verificationRequired, $privateNotifications): int {
            $this->discordSettings->setVerificationRequired($verificationRequired);
            $this->discordSettings->setPrivateNotificationsEnabled($privateNotifications);

            return $privateNotifications ? 0 : $this->privateNotifications->suppressPending();
        });

        $this->states->saveCurrentStep(AllianceSetupStep::Recruitment);
        $this->audit->success('alliance_setup', 'discord_preferences_updated', $actor, [
            'configured_now' => $configureNow,
            'verification_required' => $verificationRequired,
            'private_notifications_enabled' => $privateNotifications,
            'pending_notifications_suppressed' => $suppressedCount,
        ], 'Discord setup preferences updated.');
    }

    public function saveRecruitment(User $actor, bool $enabled, ?int $approvedPositionId, ?string $approvalMessage): void
    {
        $this->assertUsableState();

        DB::transaction(function () use ($enabled, $approvedPositionId, $approvalMessage): void {
            $this->applicationSettings->setEnabled($enabled);

            if ($enabled) {
                $this->applicationSettings->setApprovedPositionId((int) $approvedPositionId);
                $this->applicationSettings->setApprovalMessageTemplate((string) $approvalMessage);
            }
        });

        $this->states->saveCurrentStep(AllianceSetupStep::Review);
        $this->audit->success('alliance_setup', 'recruitment_preferences_updated', $actor, [
            'applications_enabled' => $enabled,
            'approved_position_id' => $enabled ? $approvedPositionId : null,
        ], 'Recruitment setup preferences updated.');
    }

    public function complete(User $actor): void
    {
        $this->assertUsableState();

        DB::transaction(function () use ($actor): void {
            $snapshot = $this->readiness->snapshot($actor);

            if (! $snapshot['ready']) {
                throw ValidationException::withMessages([
                    'setup' => 'Resolve the required platform and data blockers before completing setup.',
                ]);
            }

            $state = $this->states->complete($actor->getKey());
            $this->audit->recordAfterCommit('alliance_setup', 'setup_completed', subject: $actor, context: [
                'setup_version' => $state->version,
                'resolved_checks' => array_keys($snapshot['required']),
                'outstanding_warnings' => array_keys($snapshot['warnings']),
            ], message: 'Guided alliance setup completed.');
        }, 3);
    }

    private function assertUsableState(): void
    {
        if ($this->states->read()->corrupt) {
            throw ValidationException::withMessages([
                'setup' => 'Setup metadata is invalid. Reset only the setup metadata before continuing.',
            ]);
        }
    }
}
