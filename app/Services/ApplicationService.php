<?php

namespace App\Services;

use App\Enums\AlliancePositionEnum;
use App\Enums\ApplicationStatus;
use App\Exceptions\ApplicationException;
use App\Exceptions\PWEntityDoesNotExist;
use App\GraphQL\Models\Nation;
use App\Models\Application;
use App\Models\ApplicationMessage;
use App\Models\DiscordAccount;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Throwable;

class ApplicationService
{
    public function __construct(
        private readonly AllianceMembershipService $membershipService,
        private readonly AlliancePositionService $alliancePositionService,
    ) {}

    /**
     * Start a recruitment application initiated from Discord.
     *
     * @throws ApplicationException
     */
    public function createApplicationFromDiscord(int $nationId, string $discordUserId, string $discordUsername): Application
    {
        $this->assertApplicationsEnabled();

        $nation = $this->fetchNation($nationId);

        $this->assertApplicantEligible($nation);

        $this->assertNoPendingApplication($nationId, $discordUserId);

        try {
            return Application::query()->create([
                'nation_id' => $nationId,
                'leader_name_snapshot' => $nation->leader_name ?? '',
                'discord_user_id' => $discordUserId,
                'discord_username' => $discordUsername,
                'status' => ApplicationStatus::Pending,
                'pending_key' => 1,
            ]);
        } catch (QueryException $exception) {
            if ($this->isUniqueConstraintViolation($exception)) {
                throw new ApplicationException(
                    'pending_application_exists',
                    'An application is already pending for this nation or Discord user.',
                    422
                );
            }

            throw $exception;
        }
    }

    /**
     * Expose nation details for clients that need richer context.
     *
     * @throws ApplicationException
     */
    public function getNation(int $nationId): Nation
    {
        return $this->fetchNation($nationId);
    }

    /**
     * Attach the Discord channel ID for the interview.
     */
    public function attachChannelToApplication(Application $application, string $discordChannelId): Application
    {
        $application->discord_channel_id = $discordChannelId;
        $application->save();

        return $application;
    }

    /**
     * Persist a Discord interview message against the linked application.
     *
     * @param  array{
     *     discord_channel_id: string,
     *     discord_message_id: string,
     *     discord_user_id: string,
     *     discord_username: string,
     *     content: string,
     *     sent_at: string|int,
     *     is_staff: bool
     * }  $payload
     */
    public function logDiscordMessage(array $payload): ?ApplicationMessage
    {
        $application = Application::query()
            ->where('discord_channel_id', $payload['discord_channel_id'])
            ->latest('created_at')
            ->first();

        if (! $application || $application->status !== ApplicationStatus::Pending) {
            return null;
        }

        return ApplicationMessage::query()->create([
            'application_id' => $application->id,
            'discord_message_id' => $payload['discord_message_id'],
            'discord_user_id' => $payload['discord_user_id'],
            'discord_username' => $payload['discord_username'],
            'discord_channel_id' => $payload['discord_channel_id'],
            'content' => $payload['content'],
            'is_staff' => $payload['is_staff'],
            'sent_at' => $this->parseTimestamp($payload['sent_at']),
        ]);
    }

    /**
     * Approve a pending application via a Discord moderator.
     *
     * @throws ApplicationException
     */
    public function approveByDiscordUser(
        string $applicantDiscordId,
        string $moderatorDiscordId,
        ?string $approvalRequestId = null
    ): Application {
        $moderator = $this->resolveModerator($moderatorDiscordId);

        try {
            return Cache::lock($this->applicationDecisionLockKey($applicantDiscordId), 30)
                ->block(25, function () use ($applicantDiscordId, $moderatorDiscordId, $approvalRequestId, $moderator): Application {
                    $existingApplication = $this->findApprovedApplicationForRetry(
                        $applicantDiscordId,
                        $moderatorDiscordId,
                        $approvalRequestId
                    );

                    if ($existingApplication) {
                        return $existingApplication;
                    }

                    return $this->approvePendingApplication($applicantDiscordId, $moderatorDiscordId, $approvalRequestId, $moderator);
                });
        } catch (LockTimeoutException) {
            throw new ApplicationException(
                'approval_in_progress',
                'Approval is already in progress for this applicant. Please try again shortly.',
                409
            );
        }
    }

    /**
     * @throws ApplicationException
     */
    private function approvePendingApplication(
        string $applicantDiscordId,
        string $moderatorDiscordId,
        ?string $approvalRequestId,
        User $moderator
    ): Application {
        $application = $this->findPendingApplication($applicantDiscordId);
        $nation = $this->fetchNation($application->nation_id);

        $this->assertNationInAlliance($nation);

        try {
            $this->alliancePositionService->approveMember($application->nation_id);
        } catch (Throwable $e) {
            Log::error('Failed to approve applicant in-game', [
                'application_id' => $application->id,
                'nation_id' => $application->nation_id,
                'applicant_discord_id' => $application->discord_user_id,
                'moderator_discord_id' => $moderatorDiscordId,
                'error' => $e->getMessage(),
            ]);

            throw new ApplicationException(
                'alliance_update_failed',
                'Unable to update alliance position at this time.'
            );
        }

        $application->status = ApplicationStatus::Approved;
        $application->pending_key = null;
        $application->approved_at = Carbon::now();
        $application->approved_by_discord_id = $moderatorDiscordId;
        $application->approval_request_id = $approvalRequestId;
        $application->save();

        Log::info('Application approved', [
            'application_id' => $application->id,
            'nation_id' => $application->nation_id,
            'applicant_discord_id' => $application->discord_user_id,
            'moderator_discord_id' => $moderatorDiscordId,
        ]);

        app(AuditLogger::class)->recordAfterCommit(
            category: 'applications',
            action: 'application_approved',
            outcome: 'success',
            severity: 'info',
            subject: $application,
            context: [
                'data' => [
                    'nation_id' => $application->nation_id,
                    'applicant_discord_id' => $application->discord_user_id,
                    'moderator_discord_id' => $moderatorDiscordId,
                ],
            ],
            message: 'Application approved.',
            actorOverride: [
                'type' => 'user',
                'id' => $moderator->id,
                'name' => $moderator->name,
            ]
        );

        return $application;
    }

    /**
     * Deny a pending application via a Discord moderator.
     *
     * @throws ApplicationException
     */
    public function denyByDiscordUser(
        string $applicantDiscordId,
        string $moderatorDiscordId,
        ?string $denialRequestId = null
    ): Application {
        $moderator = $this->resolveModerator($moderatorDiscordId);

        try {
            return Cache::lock($this->applicationDecisionLockKey($applicantDiscordId), 30)
                ->block(25, function () use ($applicantDiscordId, $moderatorDiscordId, $denialRequestId, $moderator): Application {
                    $existingApplication = $this->findDeniedApplicationForRetry(
                        $applicantDiscordId,
                        $moderatorDiscordId,
                        $denialRequestId
                    );

                    if ($existingApplication) {
                        return $existingApplication;
                    }

                    return $this->denyPendingApplication($applicantDiscordId, $moderatorDiscordId, $denialRequestId, $moderator);
                });
        } catch (LockTimeoutException) {
            throw new ApplicationException(
                'denial_in_progress',
                'Denial is already in progress for this applicant. Please try again shortly.',
                409
            );
        }
    }

    /**
     * @throws ApplicationException
     */
    private function denyPendingApplication(
        string $applicantDiscordId,
        string $moderatorDiscordId,
        ?string $denialRequestId,
        User $moderator
    ): Application {
        $application = $this->findPendingApplication($applicantDiscordId);
        $nation = $this->fetchNation($application->nation_id);
        if ($this->isNationInAlliance($nation)) {
            try {
                $this->alliancePositionService->removeMember($application->nation_id);
            } catch (Throwable $e) {
                Log::error('Failed to deny applicant in-game', [
                    'application_id' => $application->id,
                    'nation_id' => $application->nation_id,
                    'applicant_discord_id' => $application->discord_user_id,
                    'moderator_discord_id' => $moderatorDiscordId,
                    'error' => $e->getMessage(),
                ]);

                throw new ApplicationException(
                    'alliance_update_failed',
                    'Unable to update alliance position at this time.'
                );
            }
        }

        $application->status = ApplicationStatus::Denied;
        $application->pending_key = null;
        $application->denied_at = Carbon::now();
        $application->denied_by_discord_id = $moderatorDiscordId;
        $application->denial_request_id = $denialRequestId;
        $application->save();

        Log::info('Application denied', [
            'application_id' => $application->id,
            'nation_id' => $application->nation_id,
            'applicant_discord_id' => $application->discord_user_id,
            'moderator_discord_id' => $moderatorDiscordId,
        ]);

        app(AuditLogger::class)->recordAfterCommit(
            category: 'applications',
            action: 'application_denied',
            outcome: 'denied',
            severity: 'warning',
            subject: $application,
            context: [
                'data' => [
                    'nation_id' => $application->nation_id,
                    'applicant_discord_id' => $application->discord_user_id,
                    'moderator_discord_id' => $moderatorDiscordId,
                ],
            ],
            message: 'Application denied.',
            actorOverride: [
                'type' => 'user',
                'id' => $moderator->id,
                'name' => $moderator->name,
            ]
        );

        return $application;
    }

    /**
     * Cancel an application from the admin UI.
     *
     * @throws ApplicationException
     */
    public function cancel(Application $application, User $actor): Application
    {
        if (! Gate::forUser($actor)->allows('manage-applications')) {
            throw new ApplicationException(
                'forbidden',
                'You do not have permission to cancel applications.',
                403
            );
        }

        if ($application->status !== ApplicationStatus::Pending) {
            throw new ApplicationException(
                'invalid_status',
                'Only pending applications may be cancelled.',
                422
            );
        }

        $application->status = ApplicationStatus::Cancelled;
        $application->pending_key = null;
        $application->cancelled_at = Carbon::now();
        $application->cancelled_by_discord_id = $actor->activeDiscordAccount()?->discord_id;
        $application->save();

        Log::info('Application cancelled', [
            'application_id' => $application->id,
            'nation_id' => $application->nation_id,
            'actor_id' => $actor->id,
        ]);

        app(AuditLogger::class)->recordAfterCommit(
            category: 'applications',
            action: 'application_cancelled',
            outcome: 'success',
            severity: 'warning',
            subject: $application,
            context: [
                'data' => [
                    'nation_id' => $application->nation_id,
                ],
            ],
            message: 'Application cancelled.',
            actorOverride: [
                'type' => 'user',
                'id' => $actor->id,
                'name' => $actor->name,
            ]
        );

        return $application;
    }

    /**
     * Return Discord configuration relevant to the bot.
     *
     * @return array<string, string>
     */
    public function getDiscordConfig(): array
    {
        return [
            'applicant_role_id' => SettingService::getApplicationsDiscordApplicantRoleId(),
            'ia_role_id' => SettingService::getApplicationsDiscordIaRoleId(),
            'member_role_id' => SettingService::getApplicationsDiscordMemberRoleId(),
            'interview_category_id' => SettingService::getApplicationsDiscordInterviewCategoryId(),
            'approval_announcement_channel_id' => SettingService::getApplicationsApprovalAnnouncementChannelId(),
            'approval_message_template' => SettingService::getApplicationsApprovalMessageTemplate(),
            'join_url' => $this->joinUrl(),
        ];
    }

    /**
     * @throws ApplicationException
     */
    protected function assertApplicationsEnabled(): void
    {
        if (! SettingService::isApplicationsEnabled()) {
            throw new ApplicationException('system_disabled', 'Applications are currently disabled.', 403);
        }
    }

    /**
     * @throws ApplicationException
     */
    protected function fetchNation(int $nationId): Nation
    {
        try {
            return NationQueryService::getNationById($nationId);
        } catch (PWEntityDoesNotExist $e) {
            throw new ApplicationException('nation_not_found', 'Nation not found.', 404, context: [
                'join_url' => $this->joinUrl(),
            ]);
        } catch (Throwable $e) {
            Log::error('Failed to fetch nation for application', [
                'nation_id' => $nationId,
                'error' => $e->getMessage(),
            ]);

            throw new ApplicationException(
                'nation_lookup_failed',
                'Unable to validate the nation at this time.'
            );
        }
    }

    /**
     * @throws ApplicationException
     */
    protected function assertApplicantEligible(Nation $nation): void
    {
        $primaryAllianceId = $this->membershipService->getPrimaryAllianceId();

        if ((int) ($nation->alliance_id ?? 0) !== $primaryAllianceId) {
            throw new ApplicationException(
                'nation_not_in_our_alliance',
                'The nation must join our alliance before applying.',
                422,
                ['join_url' => $this->joinUrl()]
            );
        }

        if ($nation->alliance_position !== AlliancePositionEnum::APPLICANT->value) {
            throw new ApplicationException(
                'nation_not_applicant',
                'The nation must be marked as an applicant in the alliance.',
                422,
                ['join_url' => $this->joinUrl()]
            );
        }
    }

    /**
     * @throws ApplicationException
     */
    protected function assertNationInAlliance(Nation $nation): void
    {
        if (! $this->isNationInAlliance($nation)) {
            throw new ApplicationException(
                'nation_not_in_our_alliance',
                'The nation is no longer in our alliance.',
                422,
                ['join_url' => $this->joinUrl()]
            );
        }
    }

    protected function isNationInAlliance(Nation $nation): bool
    {
        $primaryAllianceId = $this->membershipService->getPrimaryAllianceId();

        return (int) ($nation->alliance_id ?? 0) === $primaryAllianceId;
    }

    /**
     * @throws ApplicationException
     */
    protected function assertNoPendingApplication(int $nationId, string $discordUserId): void
    {
        $exists = Application::query()
            ->where('status', ApplicationStatus::Pending->value)
            ->where(function ($query) use ($nationId, $discordUserId) {
                $query->where('nation_id', $nationId)
                    ->orWhere('discord_user_id', $discordUserId);
            })
            ->exists();

        if ($exists) {
            throw new ApplicationException(
                'pending_application_exists',
                'An application is already pending for this nation or Discord user.',
                422
            );
        }
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        return (string) ($exception->errorInfo[0] ?? '') === '23000';
    }

    /**
     * @throws ApplicationException
     */
    protected function resolveModerator(string $discordId): User
    {
        $account = DiscordAccount::query()
            ->where('discord_id', $discordId)
            ->whereNull('unlinked_at')
            ->latest('linked_at')
            ->first();

        if (! $account?->user) {
            throw new ApplicationException(
                'moderator_not_found',
                'Moderator account is not linked to Nexus.',
                403
            );
        }

        if (! Gate::forUser($account->user)->allows('manage-applications')) {
            throw new ApplicationException(
                'forbidden',
                'You do not have permission to manage applications.',
                403
            );
        }

        return $account->user;
    }

    /**
     * @throws ApplicationException
     */
    protected function findPendingApplication(string $applicantDiscordId): Application
    {
        $application = Application::query()
            ->where('discord_user_id', $applicantDiscordId)
            ->where('status', ApplicationStatus::Pending->value)
            ->latest('created_at')
            ->first();

        if (! $application) {
            throw new ApplicationException(
                'pending_application_missing',
                'No pending application found for this applicant.',
                404
            );
        }

        return $application;
    }

    protected function findApprovedApplicationForRetry(
        string $applicantDiscordId,
        string $moderatorDiscordId,
        ?string $approvalRequestId
    ): ?Application {
        if ($approvalRequestId) {
            return Application::query()
                ->where('discord_user_id', $applicantDiscordId)
                ->where('status', ApplicationStatus::Approved->value)
                ->where('approval_request_id', $approvalRequestId)
                ->latest('approved_at')
                ->first();
        }

        if ($this->hasPendingApplication($applicantDiscordId)) {
            return null;
        }

        return Application::query()
            ->where('discord_user_id', $applicantDiscordId)
            ->where('status', ApplicationStatus::Approved->value)
            ->where('approved_by_discord_id', $moderatorDiscordId)
            ->where('approved_at', '>=', Carbon::now()->subMinutes(10))
            ->latest('approved_at')
            ->first();
    }

    protected function findDeniedApplicationForRetry(
        string $applicantDiscordId,
        string $moderatorDiscordId,
        ?string $denialRequestId
    ): ?Application {
        if ($denialRequestId) {
            return Application::query()
                ->where('discord_user_id', $applicantDiscordId)
                ->where('status', ApplicationStatus::Denied->value)
                ->where('denial_request_id', $denialRequestId)
                ->latest('denied_at')
                ->first();
        }

        if ($this->hasPendingApplication($applicantDiscordId)) {
            return null;
        }

        return Application::query()
            ->where('discord_user_id', $applicantDiscordId)
            ->where('status', ApplicationStatus::Denied->value)
            ->where('denied_by_discord_id', $moderatorDiscordId)
            ->where('denied_at', '>=', Carbon::now()->subMinutes(10))
            ->latest('denied_at')
            ->first();
    }

    protected function hasPendingApplication(string $applicantDiscordId): bool
    {
        return Application::query()
            ->where('discord_user_id', $applicantDiscordId)
            ->where('status', ApplicationStatus::Pending->value)
            ->exists();
    }

    protected function applicationDecisionLockKey(string $applicantDiscordId): string
    {
        return sprintf('applications:decision:%s', $applicantDiscordId);
    }

    protected function parseTimestamp(int|string $value): Carbon
    {
        if (is_numeric($value)) {
            return Carbon::createFromTimestamp((int) $value);
        }

        return Carbon::parse($value);
    }

    protected function joinUrl(): string
    {
        return sprintf(
            'https://politicsandwar.com/alliance/join/id=%d',
            $this->membershipService->getPrimaryAllianceId()
        );
    }
}
