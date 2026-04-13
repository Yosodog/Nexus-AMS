<?php

namespace App\Jobs;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\Nation;
use App\Services\AllianceMembershipService;
use App\Services\AlliancePositionService;
use App\Services\AuditLogger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncApplicationAllianceState implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 120;

    /**
     * @var array<int, int>
     */
    public array $backoff = [30, 60, 300, 900];

    /**
     * Create a new job instance.
     */
    public function __construct(
        public readonly int $applicationId,
        public readonly ApplicationStatus $targetStatus,
        public readonly ?int $moderatorUserId = null,
        public readonly ?string $moderatorName = null,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(
        AlliancePositionService $alliancePositionService,
        AllianceMembershipService $membershipService,
        AuditLogger $auditLogger,
    ): void {
        $application = Application::query()->find($this->applicationId);

        if (! $application) {
            return;
        }

        if ($this->targetStatus === ApplicationStatus::Approved) {
            $alliancePositionService->approveMember($application->nation_id);

            Log::info('Application approval synced to alliance service.', [
                'application_id' => $application->id,
                'nation_id' => $application->nation_id,
                'applicant_discord_id' => $application->discord_user_id,
            ]);

            $auditLogger->success(
                category: 'applications',
                action: 'application_approval_synced',
                subject: $application,
                context: [
                    'data' => [
                        'nation_id' => $application->nation_id,
                        'applicant_discord_id' => $application->discord_user_id,
                    ],
                ],
                message: 'Application approval synced to the alliance service.',
                actorOverride: $this->actorOverride()
            );

            return;
        }

        if ($this->targetStatus === ApplicationStatus::Denied) {
            $nation = Nation::query()
                ->select(['id', 'alliance_id'])
                ->find($application->nation_id);

            if ($nation && $membershipService->contains((int) $nation->alliance_id)) {
                $alliancePositionService->removeMember($application->nation_id);
            }

            Log::info('Application denial synced to alliance service.', [
                'application_id' => $application->id,
                'nation_id' => $application->nation_id,
                'applicant_discord_id' => $application->discord_user_id,
            ]);

            $auditLogger->denied(
                category: 'applications',
                action: 'application_denial_synced',
                subject: $application,
                context: [
                    'data' => [
                        'nation_id' => $application->nation_id,
                        'applicant_discord_id' => $application->discord_user_id,
                    ],
                ],
                message: 'Application denial synced to the alliance service.',
                actorOverride: $this->actorOverride()
            );
        }
    }

    public function failed(Throwable $exception): void
    {
        $application = Application::query()->find($this->applicationId);

        Log::error('Failed to sync application state to alliance service.', [
            'application_id' => $this->applicationId,
            'target_status' => $this->targetStatus->value,
            'error' => $exception->getMessage(),
        ]);

        app(AuditLogger::class)->failure(
            category: 'applications',
            action: $this->targetStatus === ApplicationStatus::Approved
                ? 'application_approval_sync_failed'
                : 'application_denial_sync_failed',
            subject: $application,
            context: [
                'data' => [
                    'nation_id' => $application?->nation_id,
                    'applicant_discord_id' => $application?->discord_user_id,
                    'error' => $exception->getMessage(),
                ],
            ],
            message: $this->targetStatus === ApplicationStatus::Approved
                ? 'Application approval could not sync to the alliance service.'
                : 'Application denial could not sync to the alliance service.',
            actorOverride: $this->actorOverride()
        );
    }

    /**
     * @return array{type: string, id: int|null, name: string|null}
     */
    private function actorOverride(): array
    {
        return [
            'type' => 'user',
            'id' => $this->moderatorUserId,
            'name' => $this->moderatorName,
        ];
    }
}
