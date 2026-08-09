<?php

namespace App\Services\Discord;

use App\Enums\DiscordQueueLane;
use App\Enums\DiscordQueueStatus;
use App\Models\Application;
use App\Models\DiscordQueue;
use App\Services\AuditLogger;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;
use Throwable;

final class ApplicationDiscordReconciliationService
{
    public const ACTION = 'APPLICATION_DISCORD_RECONCILE';

    public function __construct(
        private readonly ApplicationDiscordReconciliationPlanFactory $plans,
        private readonly DiscordConnectionResolver $connections,
        private readonly DiscordQueueService $queue,
    ) {}

    /**
     * Queue the exact Nexus-owned Discord state for an application.
     *
     * A matching current queue item is reused. A forced repair creates a new
     * revision and suppresses one-time messages.
     */
    public function reconcile(
        Application $application,
        ?DiscordConnectionContext $connection = null,
        bool $force = false,
    ): Application {
        if (! $application->exists || (int) $application->getKey() < 1) {
            throw new ApplicationDiscordReconciliationException(
                'application_not_persisted',
                'A persisted application is required for Discord reconciliation.',
                422,
            );
        }

        try {
            return Cache::lock('applications:discord-reconcile:'.$application->getKey(), 30)
                ->block(25, function () use ($application, $connection, $force): Application {
                    return DB::transaction(function () use ($application, $connection, $force): Application {
                        $locked = Application::query()->lockForUpdate()->findOrFail($application->getKey());
                        $resolved = $this->resolveConnection($locked, $connection);

                        if (! $resolved->supportsQueueAction(self::ACTION)) {
                            throw new ApplicationDiscordReconciliationException(
                                'discord_queue_action_unsupported',
                                'The connected Discord bot does not support application reconciliation.',
                                409,
                            );
                        }

                        $currentRevision = max(0, (int) $locked->discord_reconcile_revision);
                        $candidateRevision = $currentRevision + 1;
                        $plan = $this->makePlan($locked, $resolved, $candidateRevision, ! $force);
                        $currentQueue = $this->lockCurrentQueue($locked);

                        if (! $force && $this->canReuse($locked, $currentQueue, $resolved, $plan)) {
                            $this->persistBinding($locked, $resolved, $plan['issues']);

                            return $locked->fresh();
                        }

                        $queue = $this->queue->enqueue(
                            action: self::ACTION,
                            payload: $plan['payload'],
                            dedupeKey: 'application-reconcile:'.$locked->getKey().':'.$candidateRevision,
                            lane: DiscordQueueLane::SideEffects,
                            priority: 80,
                            guildId: $resolved->guildId,
                            connection: $resolved,
                        );

                        $this->supersedePendingQueue($locked, $currentQueue);
                        $locked->forceFill([
                            'discord_connection_id' => $resolved->connectionId,
                            'discord_connection_generation' => $resolved->generation,
                            'discord_application_id' => $resolved->applicationId,
                            'discord_guild_id' => $resolved->guildId,
                            'discord_reconcile_revision' => $candidateRevision,
                            'discord_reconcile_queue_id' => $queue->getKey(),
                            'discord_reconcile_desired_hash' => $plan['desired_hash'],
                            'discord_reconcile_issues' => $plan['issues'],
                        ])->save();

                        app(AuditLogger::class)->recordAfterCommit(
                            category: 'applications',
                            action: 'application_discord_reconciliation_queued',
                            outcome: 'success',
                            severity: 'info',
                            subject: $locked,
                            context: ['data' => [
                                'connection_id' => $resolved->connectionId,
                                'connection_generation' => $resolved->generation,
                                'guild_id' => $resolved->guildId,
                                'queue_id' => $queue->getKey(),
                                'revision' => $candidateRevision,
                                'forced_repair' => $force,
                            ]],
                            message: 'Discord application reconciliation queued.',
                        );

                        return $locked->fresh();
                    }, attempts: 3);
                });
        } catch (ApplicationDiscordReconciliationException $exception) {
            throw $exception;
        } catch (DiscordConnectionResolutionException $exception) {
            throw new ApplicationDiscordReconciliationException(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->status,
                $exception,
            );
        } catch (LockTimeoutException $exception) {
            throw new ApplicationDiscordReconciliationException(
                'application_reconciliation_in_progress',
                'Discord application reconciliation is already in progress. Please try again shortly.',
                409,
                $exception,
            );
        } catch (InvalidArgumentException|JsonException $exception) {
            throw new ApplicationDiscordReconciliationException(
                'application_reconciliation_plan_invalid',
                'Nexus could not build a safe Discord application reconciliation plan.',
                422,
                $exception,
            );
        } catch (Throwable $exception) {
            throw new ApplicationDiscordReconciliationException(
                'application_reconciliation_queue_failed',
                'Nexus could not queue Discord application reconciliation.',
                503,
                $exception,
            );
        }
    }

    private function resolveConnection(
        Application $application,
        ?DiscordConnectionContext $connection,
    ): DiscordConnectionContext {
        $resolved = $connection === null
            ? $this->connections->resolveForQueueProducer($application->discord_connection_id)
            : $this->connections->resolveV2(
                $connection->connectionId,
                $connection->applicationId,
                $connection->guildId,
                $connection->generation,
            );

        $bindings = [
            'discord_connection_id' => $resolved->connectionId,
            'discord_application_id' => $resolved->applicationId,
            'discord_guild_id' => $resolved->guildId,
        ];

        foreach ($bindings as $field => $expected) {
            $current = $application->{$field};

            if ($current !== null && ! hash_equals((string) $current, $expected)) {
                throw new ApplicationDiscordReconciliationException(
                    'application_discord_binding_mismatch',
                    'The application belongs to a different Discord installation.',
                    409,
                );
            }
        }

        return $resolved;
    }

    /**
     * @return array{payload: array<string, mixed>, issues: list<string>, desired_hash: string}
     */
    private function makePlan(
        Application $application,
        DiscordConnectionContext $connection,
        int $revision,
        bool $includeOneTimeMessages,
    ): array {
        return $this->plans->make(
            $application,
            $connection->applicationId,
            $connection->guildId,
            $connection->connectionId,
            $connection->generation,
            $revision,
            $includeOneTimeMessages,
        );
    }

    private function lockCurrentQueue(Application $application): ?DiscordQueue
    {
        $queueId = trim((string) $application->discord_reconcile_queue_id);

        return $queueId === ''
            ? null
            : DiscordQueue::query()->whereKey($queueId)->lockForUpdate()->first();
    }

    /**
     * @param  array{payload: array<string, mixed>, issues: list<string>, desired_hash: string}  $plan
     */
    private function canReuse(
        Application $application,
        ?DiscordQueue $queue,
        DiscordConnectionContext $connection,
        array $plan,
    ): bool {
        $currentHash = $application->discord_reconcile_desired_hash;
        if (! is_string($currentHash)
            || ! hash_equals($currentHash, $plan['desired_hash'])
            || ! $queue
            || $queue->action !== self::ACTION
            || ! in_array($queue->status, [
                DiscordQueueStatus::Pending,
                DiscordQueueStatus::Processing,
                DiscordQueueStatus::Complete,
            ], true)
            || ! $this->queueMatchesConnection($queue, $connection)) {
            return false;
        }

        $expectedPayload = $plan['payload'];
        $expectedPayload['application']['revision'] = (int) $application->discord_reconcile_revision;

        return $queue->payload === $expectedPayload;
    }

    private function queueMatchesConnection(
        DiscordQueue $queue,
        DiscordConnectionContext $connection,
    ): bool {
        return hash_equals($connection->connectionId, (string) $queue->connection_id)
            && hash_equals($connection->applicationId, (string) $queue->application_id)
            && $connection->generation === (int) $queue->connection_generation
            && hash_equals($connection->guildId, (string) $queue->guild_id);
    }

    /** @param list<string> $issues */
    private function persistBinding(
        Application $application,
        DiscordConnectionContext $connection,
        array $issues,
    ): void {
        $application->forceFill([
            'discord_connection_id' => $connection->connectionId,
            'discord_connection_generation' => $connection->generation,
            'discord_application_id' => $connection->applicationId,
            'discord_guild_id' => $connection->guildId,
            'discord_reconcile_issues' => $issues,
        ]);

        if ($application->isDirty()) {
            $application->save();
        }
    }

    private function supersedePendingQueue(Application $application, ?DiscordQueue $queue): void
    {
        if (! $queue
            || $queue->action !== self::ACTION
            || $queue->status !== DiscordQueueStatus::Pending
            || (int) data_get($queue->payload, 'application.id') !== (int) $application->getKey()) {
            return;
        }

        $queue->forceFill([
            'status' => DiscordQueueStatus::Failed,
            'last_error' => [
                'code' => 'superseded_application_revision',
                'message' => 'A newer application reconciliation revision replaced this queue item.',
                'retryable' => false,
            ],
            'completed_at' => now(),
        ])->save();
    }
}
