<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Exceptions\ApplicationException;
use App\GraphQL\Models\Nation;
use App\Models\Application;
use App\Models\ApplicationMessage;
use App\Models\DiscordAccount;
use App\Models\Nation as NationRecord;
use App\Models\User;
use App\Services\Applications\ApplicationApplicantValidator;
use App\Services\Applications\ApplicationNationLookup;
use App\Services\Discord\ApplicationDiscordReconciliationException;
use App\Services\Discord\ApplicationDiscordReconciliationService;
use App\Services\Discord\DiscordConnectionContext;
use App\Services\Discord\DiscordConnectionResolver;
use App\Services\Discord\PrivateNotificationService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ApplicationService
{
    private readonly ApplicationNationLookup $nationLookup;

    private readonly ApplicationApplicantValidator $applicantValidator;

    public function __construct(
        private readonly AllianceMembershipService $membershipService,
        private readonly AlliancePositionService $alliancePositionService,
        ?ApplicationNationLookup $nationLookup = null,
        ?ApplicationApplicantValidator $applicantValidator = null,
    ) {
        $this->nationLookup = $nationLookup ?? new ApplicationNationLookup;
        $this->applicantValidator = $applicantValidator ?? new ApplicationApplicantValidator($membershipService);
    }

    public function approveById(
        Application $application,
        User $moderator,
        string $moderatorDiscordId,
        ?string $requestId = null,
        ?DiscordConnectionContext $connection = null,
    ): Application {
        if (! Gate::forUser($moderator)->allows('manage-applications')) {
            throw new ApplicationException('forbidden', 'You do not have permission to approve applications.', 403);
        }
        $this->assertApplicationConnection($application, $connection);

        $application = Cache::lock('applications:decision:id:'.$application->id, 30)->block(25, function () use ($application, $moderator, $moderatorDiscordId, $requestId, $connection): Application {
            return DB::transaction(function () use ($application, $moderator, $moderatorDiscordId, $requestId, $connection): Application {
                $application = Application::query()->lockForUpdate()->findOrFail($application->id);
                $this->assertApplicationConnection($application, $connection);
                if ($application->status === ApplicationStatus::Approved && $requestId !== null && $application->approval_request_id === $requestId) {
                    return $application;
                }
                if ($application->status !== ApplicationStatus::Pending) {
                    throw new ApplicationException('invalid_status', 'Only pending applications may be approved.', 409);
                }

                $this->assertNationInAlliance($this->fetchNationInAlliance($application->nation_id));
                $this->syncAllianceDecisionOrFail(
                    $application,
                    ApplicationStatus::Approved,
                    $moderator,
                    $moderatorDiscordId,
                );
                $application->forceFill([
                    'status' => ApplicationStatus::Approved,
                    'pending_key' => null,
                    'approved_at' => now(),
                    'approved_by_discord_id' => $moderatorDiscordId,
                    'approval_request_id' => $requestId,
                ])->save();
                app(AuditLogger::class)->recordAfterCommit(
                    category: 'applications', action: 'application_approved', outcome: 'success', severity: 'info', subject: $application,
                    context: ['data' => ['nation_id' => $application->nation_id, 'moderator_discord_id' => $moderatorDiscordId]],
                    message: 'Application approved.', actorOverride: ['type' => 'user', 'id' => $moderator->id, 'name' => $moderator->name],
                );
                DB::afterCommit(fn () => $this->queueApplicationNotification($application, 'approved'));

                return $application->fresh();
            }, attempts: 3);
        });

        return $this->reconcileDiscordApplication($application, $connection);
    }

    public function denyById(
        Application $application,
        User $moderator,
        string $moderatorDiscordId,
        string $reason,
        ?string $requestId = null,
        ?DiscordConnectionContext $connection = null,
    ): Application {
        if (! Gate::forUser($moderator)->allows('manage-applications')) {
            throw new ApplicationException('forbidden', 'You do not have permission to deny applications.', 403);
        }
        $this->assertApplicationConnection($application, $connection);

        $application = Cache::lock('applications:decision:id:'.$application->id, 30)->block(25, function () use ($application, $moderator, $moderatorDiscordId, $reason, $requestId, $connection): Application {
            return DB::transaction(function () use ($application, $moderator, $moderatorDiscordId, $reason, $requestId, $connection): Application {
                $application = Application::query()->lockForUpdate()->findOrFail($application->id);
                $this->assertApplicationConnection($application, $connection);
                if ($application->status === ApplicationStatus::Denied && $requestId !== null && $application->denial_request_id === $requestId) {
                    return $application;
                }
                if ($application->status !== ApplicationStatus::Pending) {
                    throw new ApplicationException('invalid_status', 'Only pending applications may be denied.', 409);
                }

                $this->syncAllianceDecisionOrFail(
                    $application,
                    ApplicationStatus::Denied,
                    $moderator,
                    $moderatorDiscordId,
                );
                $application->forceFill([
                    'status' => ApplicationStatus::Denied,
                    'pending_key' => null,
                    'denied_at' => now(),
                    'denied_by_discord_id' => $moderatorDiscordId,
                    'denial_request_id' => $requestId,
                    'denial_reason' => $reason,
                ])->save();
                app(AuditLogger::class)->recordAfterCommit(
                    category: 'applications', action: 'application_denied', outcome: 'denied', severity: 'warning', subject: $application,
                    context: ['data' => ['nation_id' => $application->nation_id, 'moderator_discord_id' => $moderatorDiscordId, 'reason' => $reason]],
                    message: 'Application denied.', actorOverride: ['type' => 'user', 'id' => $moderator->id, 'name' => $moderator->name],
                );
                DB::afterCommit(fn () => $this->queueApplicationNotification($application, 'denied'));

                return $application->fresh();
            }, attempts: 3);
        });

        return $this->reconcileDiscordApplication($application, $connection);
    }

    private function queueApplicationNotification(Application $application, string $status): void
    {
        $nation = NationRecord::query()->with('user.discordAccounts')->find($application->nation_id);
        if (! $nation) {
            return;
        }

        app(PrivateNotificationService::class)->enqueueForNation(
            $nation,
            'applications',
            'application_'.$status,
            'application-'.$application->id.'-'.$status,
            ['type' => 'application', 'id' => $application->id],
            route('apply.show', absolute: false),
            ['status' => $status],
        );
    }

    /**
     * @throws ApplicationException
     */
    private function syncAllianceDecisionOrFail(
        Application $application,
        ApplicationStatus $targetStatus,
        User $moderator,
        string $moderatorDiscordId,
    ): void {
        try {
            if ($targetStatus === ApplicationStatus::Approved) {
                $this->alliancePositionService->approveMember($application->nation_id);

                return;
            }

            $nation = $this->fetchNationInAlliance($application->nation_id);

            if ($this->isNationInAlliance($nation)) {
                $this->alliancePositionService->removeMember($application->nation_id);
            }
        } catch (ApplicationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $action = $targetStatus === ApplicationStatus::Approved
                ? 'application_approval_sync_failed'
                : 'application_denial_sync_failed';

            Log::error('Failed to sync application decision to Politics & War.', [
                'application_id' => $application->id,
                'nation_id' => $application->nation_id,
                'target_status' => $targetStatus->value,
                'applicant_discord_id' => $application->discord_user_id,
                'moderator_discord_id' => $moderatorDiscordId,
                'error' => $exception->getMessage(),
            ]);

            app(AuditLogger::class)->failure(
                category: 'applications',
                action: $action,
                subject: $application,
                context: [
                    'data' => [
                        'nation_id' => $application->nation_id,
                        'applicant_discord_id' => $application->discord_user_id,
                        'moderator_discord_id' => $moderatorDiscordId,
                        'error' => Str::limit($exception->getMessage(), 500, ''),
                    ],
                ],
                message: $targetStatus === ApplicationStatus::Approved
                    ? 'Application approval could not sync to the alliance service.'
                    : 'Application denial could not sync to the alliance service.',
                actorOverride: [
                    'type' => 'user',
                    'id' => $moderator->id,
                    'name' => $moderator->name,
                ],
            );

            throw new ApplicationException(
                'alliance_update_failed',
                'Unable to update alliance position at this time.',
                503,
            );
        }
    }

    /**
     * Start a recruitment application initiated from Discord.
     *
     * @throws ApplicationException
     */
    public function createApplicationFromDiscord(
        int $nationId,
        string $discordUserId,
        string $discordUsername,
        ?DiscordConnectionContext $connection = null,
    ): Application {
        $this->assertApplicationsEnabled();

        $nation = $this->fetchEligibleApplicantNation($nationId);

        $this->assertApplicantEligible($nation);

        try {
            $application = Cache::lock($this->applicationCreationLockKey($nationId, $discordUserId), 15)
                ->block(10, function () use ($nationId, $discordUserId, $discordUsername, $nation): Application {
                    $existingApplication = $this->findMatchingPendingApplication($nationId, $discordUserId);

                    if ($existingApplication) {
                        return $existingApplication;
                    }

                    $this->assertNoConflictingPendingApplication($nationId, $discordUserId);

                    return Application::query()->create([
                        'nation_id' => $nationId,
                        'leader_name_snapshot' => $nation->leader_name ?? '',
                        'discord_user_id' => $discordUserId,
                        'discord_username' => $discordUsername,
                        'status' => ApplicationStatus::Pending,
                        'pending_key' => 1,
                    ]);
                });

            if ($application->wasRecentlyCreated) {
                Log::info('Recruitment funnel event.', [
                    'event' => 'application_submitted',
                    'channel' => 'discord',
                ]);
            }

            $this->assertApplicationConnection($application, $connection, allowUnbound: true);

            return $this->reconcileDiscordApplication($application, $connection);
        } catch (LockTimeoutException) {
            $existingApplication = $this->findMatchingPendingApplication($nationId, $discordUserId);

            if ($existingApplication) {
                $this->assertApplicationConnection($existingApplication, $connection, allowUnbound: true);

                return $this->reconcileDiscordApplication($existingApplication, $connection);
            }

            throw new ApplicationException(
                'application_creation_in_progress',
                'Application creation is already in progress for this applicant. Please try again shortly.',
                409
            );
        } catch (QueryException $exception) {
            if ($this->isUniqueConstraintViolation($exception)) {
                $existingApplication = $this->findMatchingPendingApplication($nationId, $discordUserId);

                if ($existingApplication) {
                    $this->assertApplicationConnection($existingApplication, $connection, allowUnbound: true);

                    return $this->reconcileDiscordApplication($existingApplication, $connection);
                }

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
        return DB::transaction(function () use ($application, $discordChannelId): Application {
            $locked = Application::query()->lockForUpdate()->findOrFail($application->id);

            if ($locked->discord_channel_id !== null && $locked->discord_channel_id !== $discordChannelId) {
                throw new ApplicationException(
                    'discord_channel_conflict',
                    'This application is already attached to a different Discord channel.',
                    409,
                    [
                        'application_id' => $locked->id,
                        'discord_channel_id' => $locked->discord_channel_id,
                    ],
                );
            }

            if ($locked->discord_channel_id === null) {
                $locked->discord_channel_id = $discordChannelId;
                $locked->save();
            }

            return $locked->fresh();
        }, attempts: 3);
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
     *     sent_at: string|int
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

        $author = DiscordAccount::query()
            ->where('discord_id', $payload['discord_user_id'])
            ->whereNull('unlinked_at')
            ->latest('linked_at')
            ->first()?->user;

        $isStaff = $author !== null
            && Gate::forUser($author)->allows('manage-applications');

        return ApplicationMessage::query()->firstOrCreate(
            [
                'application_id' => $application->id,
                'discord_message_id' => $payload['discord_message_id'],
            ],
            [
                'discord_user_id' => $payload['discord_user_id'],
                'discord_username' => $payload['discord_username'],
                'discord_channel_id' => $payload['discord_channel_id'],
                'content' => $payload['content'],
                'is_staff' => $isStaff,
                'sent_at' => $this->parseTimestamp($payload['sent_at']),
            ],
        );
    }

    /**
     * Approve a pending application via a Discord moderator.
     *
     * @throws ApplicationException
     */
    public function approveByDiscordUser(
        string $applicantDiscordId,
        string $moderatorDiscordId,
        string $approvalRequestId,
        ?DiscordConnectionContext $connection = null,
    ): Application {
        $moderator = $this->resolveModerator($moderatorDiscordId);

        try {
            $application = Cache::lock($this->applicationDecisionLockKey($applicantDiscordId), 30)
                ->block(25, function () use ($applicantDiscordId, $moderatorDiscordId, $approvalRequestId, $moderator, $connection): Application {
                    $existingApplication = $this->findExistingDecision(
                        $applicantDiscordId,
                        ApplicationStatus::Approved,
                        $approvalRequestId,
                    );

                    if ($existingApplication) {
                        $this->assertApplicationConnection($existingApplication, $connection);

                        return $existingApplication;
                    }

                    return $this->approvePendingApplication(
                        $applicantDiscordId,
                        $moderatorDiscordId,
                        $approvalRequestId,
                        $moderator,
                        $connection,
                    );
                });

            return $this->reconcileDiscordApplication($application, $connection);
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
        User $moderator,
        ?DiscordConnectionContext $connection = null,
    ): Application {
        $application = $this->findPendingApplication($applicantDiscordId);
        $this->assertApplicationConnection($application, $connection);
        $nation = $this->fetchNationInAlliance($application->nation_id);

        $this->assertNationInAlliance($nation);

        $this->syncAllianceDecisionOrFail(
            $application,
            ApplicationStatus::Approved,
            $moderator,
            $moderatorDiscordId,
        );

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
        string $denialRequestId,
        ?DiscordConnectionContext $connection = null,
    ): Application {
        $moderator = $this->resolveModerator($moderatorDiscordId);

        try {
            $application = Cache::lock($this->applicationDecisionLockKey($applicantDiscordId), 30)
                ->block(25, function () use ($applicantDiscordId, $moderatorDiscordId, $denialRequestId, $moderator, $connection): Application {
                    $existingApplication = $this->findExistingDecision(
                        $applicantDiscordId,
                        ApplicationStatus::Denied,
                        $denialRequestId,
                    );

                    if ($existingApplication) {
                        $this->assertApplicationConnection($existingApplication, $connection);

                        return $existingApplication;
                    }

                    return $this->denyPendingApplication(
                        $applicantDiscordId,
                        $moderatorDiscordId,
                        $denialRequestId,
                        $moderator,
                        $connection,
                    );
                });

            return $this->reconcileDiscordApplication($application, $connection);
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
        User $moderator,
        ?DiscordConnectionContext $connection = null,
    ): Application {
        $application = $this->findPendingApplication($applicantDiscordId);
        $this->assertApplicationConnection($application, $connection);

        $this->syncAllianceDecisionOrFail(
            $application,
            ApplicationStatus::Denied,
            $moderator,
            $moderatorDiscordId,
        );

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
    public function cancel(
        Application $application,
        User $actor,
        ?DiscordConnectionContext $connection = null,
    ): Application {
        if (! Gate::forUser($actor)->allows('manage-applications')) {
            throw new ApplicationException(
                'forbidden',
                'You do not have permission to cancel applications.',
                403
            );
        }
        $this->assertApplicationConnection($application, $connection);

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

        return $this->reconcileDiscordApplication($application, $connection);
    }

    /**
     * Keep an application scoped to the installation that created or first
     * reconciled it. A generation rotation on the same connection is allowed.
     *
     * @throws ApplicationException
     */
    private function assertApplicationConnection(
        Application $application,
        ?DiscordConnectionContext $connection,
        bool $allowUnbound = false,
    ): void {
        if ($connection === null) {
            return;
        }

        $bindings = [
            'discord_connection_id' => $connection->connectionId,
            'discord_application_id' => $connection->applicationId,
            'discord_guild_id' => $connection->guildId,
        ];

        $isUnbound = collect(array_keys($bindings))
            ->every(fn (string $field): bool => $application->{$field} === null);
        if ($isUnbound && ! $allowUnbound) {
            if ($connection->protocolVersion === 1 && $connection->isDedicated()) {
                return;
            }

            try {
                $default = app(DiscordConnectionResolver::class)->resolveForQueueProducer();
            } catch (Throwable) {
                throw new ApplicationException(
                    'application_installation_mismatch',
                    'The application is not available in this Discord installation.',
                    404,
                );
            }

            if (! hash_equals($default->connectionId, $connection->connectionId)) {
                throw new ApplicationException(
                    'application_installation_mismatch',
                    'The application is not available in this Discord installation.',
                    404,
                );
            }
        }

        foreach ($bindings as $field => $expected) {
            $current = $application->{$field};

            if ($current !== null && ! hash_equals((string) $current, $expected)) {
                throw new ApplicationException(
                    'application_installation_mismatch',
                    'The application is not available in this Discord installation.',
                    404,
                );
            }
        }
    }

    private function reconcileDiscordApplication(
        Application $application,
        ?DiscordConnectionContext $connection,
    ): Application {
        try {
            return app(ApplicationDiscordReconciliationService::class)->reconcile($application, $connection);
        } catch (ApplicationDiscordReconciliationException $exception) {
            $trackFailure = $connection !== null
                || $application->discord_connection_id !== null
                || (int) $application->discord_reconcile_revision > 0;
            Log::log($trackFailure ? 'warning' : 'debug', 'Discord application reconciliation was not queued.', [
                'application_id' => $application->getKey(),
                'error' => $exception->errorCode,
                'status' => $exception->status,
            ]);

            if (! $trackFailure || $exception->errorCode === 'application_discord_binding_mismatch') {
                return $application->fresh();
            }

            try {
                $application = DB::transaction(function () use ($application, $connection, $exception): Application {
                    $locked = Application::query()->lockForUpdate()->findOrFail($application->getKey());
                    $this->assertApplicationConnection($locked, $connection, allowUnbound: true);
                    $issues = collect($locked->discord_reconcile_issues ?? [])
                        ->filter(static fn (mixed $issue): bool => is_string($issue) && trim($issue) !== '')
                        ->push($exception->errorCode)
                        ->unique()
                        ->values()
                        ->all();
                    $revision = max(0, (int) $locked->discord_reconcile_revision);
                    if ($revision === 0 || $locked->discord_reconcile_queue_id !== null) {
                        $revision++;
                    }

                    $attributes = [
                        'discord_reconcile_revision' => $revision,
                        'discord_reconcile_queue_id' => null,
                        'discord_reconcile_desired_hash' => null,
                        'discord_reconcile_issues' => $issues,
                    ];
                    if ($connection !== null) {
                        $attributes += [
                            'discord_connection_id' => $connection->connectionId,
                            'discord_connection_generation' => $connection->generation,
                            'discord_application_id' => $connection->applicationId,
                            'discord_guild_id' => $connection->guildId,
                        ];
                    }

                    $locked->forceFill($attributes)->save();

                    return $locked->fresh();
                }, attempts: 3);

                app(AuditLogger::class)->recordAfterCommit(
                    category: 'applications',
                    action: 'application_discord_reconciliation_failed',
                    outcome: 'failure',
                    severity: 'warning',
                    subject: $application,
                    context: ['data' => [
                        'error' => $exception->errorCode,
                        'connection_id' => $connection?->connectionId ?? $application->discord_connection_id,
                        'connection_generation' => $connection?->generation ?? $application->discord_connection_generation,
                        'guild_id' => $connection?->guildId ?? $application->discord_guild_id,
                    ]],
                    message: 'Discord application reconciliation could not be queued.',
                );
            } catch (Throwable $recordingException) {
                Log::error('Discord application reconciliation failure could not be recorded.', [
                    'application_id' => $application->getKey(),
                    'error' => $exception->errorCode,
                    'exception' => $recordingException::class,
                ]);
            }

            return $application->fresh();
        }
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
        $localNation = $this->findLocalNationSnapshot($nationId);

        if ($localNation) {
            return $this->mapLocalNationToGraphQl($localNation);
        }

        return $this->fetchLiveNation($nationId);
    }

    protected function fetchLiveNation(int $nationId): Nation
    {
        return $this->nationLookup->fetchLive(
            $nationId,
            fn (): Nation => $this->queryNationFromApi($nationId),
            $this->joinUrl(),
        );
    }

    /**
     * @throws ApplicationException
     */
    protected function fetchEligibleApplicantNation(int $nationId): Nation
    {
        return $this->fetchLiveNation($nationId);
    }

    /**
     * @throws ApplicationException
     */
    protected function fetchNationInAlliance(int $nationId): Nation
    {
        return $this->fetchLiveNation($nationId);
    }

    protected function assertApplicantEligible(Nation $nation): void
    {
        $this->applicantValidator->assertApplicantEligible($nation, $this->joinUrl());
    }

    protected function assertNationInAlliance(Nation $nation): void
    {
        $this->applicantValidator->assertNationInAlliance($nation, $this->joinUrl());
    }

    protected function isNationInAlliance(Nation $nation): bool
    {
        return $this->applicantValidator->isNationInAlliance($nation);
    }

    /**
     * @throws ApplicationException
     */
    protected function assertNoConflictingPendingApplication(int $nationId, string $discordUserId): void
    {
        $exists = Application::query()
            ->where('status', ApplicationStatus::Pending->value)
            ->where(function ($query) use ($nationId, $discordUserId) {
                $query->where('nation_id', $nationId)
                    ->orWhere('discord_user_id', $discordUserId);
            })
            ->where(function ($query) use ($nationId, $discordUserId) {
                $query->where('nation_id', '!=', $nationId)
                    ->orWhere('discord_user_id', '!=', $discordUserId);
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

    protected function findMatchingPendingApplication(int $nationId, string $discordUserId): ?Application
    {
        return Application::query()
            ->where('nation_id', $nationId)
            ->where('discord_user_id', $discordUserId)
            ->where('status', ApplicationStatus::Pending->value)
            ->latest('created_at')
            ->first();
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        return (string) ($exception->errorInfo[0] ?? '') === '23000';
    }

    protected function queryNationFromApi(int $nationId): Nation
    {
        return $this->nationLookup->queryNationFromApi($nationId);
    }

    private function applicationCreationLockKey(int $nationId, string $discordUserId): string
    {
        return sprintf('applications:create:%d:%s', $nationId, sha1($discordUserId));
    }

    protected function findLocalNationSnapshot(int $nationId): ?NationRecord
    {
        return $this->nationLookup->findLocalNationSnapshot($nationId);
    }

    protected function mapLocalNationToGraphQl(NationRecord $nation): Nation
    {
        return $this->nationLookup->mapLocalNation($nation);
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
                'Moderator account is not linked to '.config('app.name').'.',
                403
            );
        }

        $moderator = $account->user;

        if (! $moderator->is_admin
            || $moderator->disabled
            || ! $moderator->isVerified()
            || ! Gate::forUser($moderator)->allows('manage-applications')) {
            throw new ApplicationException(
                'forbidden',
                'You do not have permission to manage applications.',
                403
            );
        }

        $requiresMfa = SettingService::isMfaRequiredForAllUsers()
            || SettingService::isMfaRequiredForAdmins();

        if ($requiresMfa && ! $moderator->hasEnabledTwoFactorAuthentication()) {
            throw new ApplicationException(
                'mfa_required',
                'Multi-factor authentication must be configured before managing applications.',
                403,
            );
        }

        return $moderator;
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

    protected function findExistingDecision(
        string $applicantDiscordId,
        ApplicationStatus $requestedStatus,
        string $requestId,
    ): ?Application {
        $requestIdColumn = $requestedStatus === ApplicationStatus::Approved
            ? 'approval_request_id'
            : 'denial_request_id';
        $oppositeRequestIdColumn = $requestedStatus === ApplicationStatus::Approved
            ? 'denial_request_id'
            : 'approval_request_id';

        $matchingDecision = Application::query()
            ->where('discord_user_id', $applicantDiscordId)
            ->where($requestIdColumn, $requestId)
            ->latest('id')
            ->first();

        if ($matchingDecision) {
            return $matchingDecision;
        }

        if (Application::query()
            ->where('discord_user_id', $applicantDiscordId)
            ->where($oppositeRequestIdColumn, $requestId)
            ->exists()) {
            throw new ApplicationException(
                'application_decision_request_conflict',
                'This Discord request ID was already used for a different application decision.',
                409,
            );
        }

        if ($this->hasPendingApplication($applicantDiscordId)) {
            return null;
        }

        $application = Application::query()
            ->where('discord_user_id', $applicantDiscordId)
            ->latest('created_at')
            ->latest('id')
            ->first();

        if (! $application) {
            return null;
        }

        if ($application->status === $requestedStatus) {
            throw new ApplicationException(
                'application_decision_request_mismatch',
                'The application was already decided by a different Discord request.',
                409,
                ['application_id' => $application->id, 'status' => $application->status->value],
            );
        }

        if ($application->status === ApplicationStatus::Denied && $requestedStatus === ApplicationStatus::Approved) {
            throw new ApplicationException(
                'application_already_denied',
                'The latest application has already been denied.',
                409,
                ['application_id' => $application->id, 'status' => $application->status->value],
            );
        }

        if ($application->status === ApplicationStatus::Approved && $requestedStatus === ApplicationStatus::Denied) {
            throw new ApplicationException(
                'application_already_approved',
                'The latest application has already been approved.',
                409,
                ['application_id' => $application->id, 'status' => $application->status->value],
            );
        }

        throw new ApplicationException(
            'application_not_pending',
            'The latest application is not pending and cannot be changed.',
            409,
            ['application_id' => $application->id, 'status' => $application->status->value],
        );
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
