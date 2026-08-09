<?php

namespace App\Services\Discord;

use App\Domain\Federation\Services\FederationOperationGuard;
use App\Enums\DiscordQueueLane;
use App\Enums\DiscordQueueStatus;
use App\Exceptions\DiscordQueueLeaseException;
use App\Models\Application;
use App\Models\DiscordQueue;
use App\Models\MilcomDispatch;
use App\Models\MilcomOperation;
use App\Services\Alerts\AlertDeliveryReceiptService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DiscordQueueLeaseService
{
    public const LEASE_MINUTES = 5;

    public const MAX_ATTEMPTS = 3;

    /**
     * @var array<string, array<int, string>>
     */
    private const CHECKPOINT_FIELDS = [
        'WAR_ROOM_CREATE' => ['discord_channel_id'],
        'CITY_TIER_SYNC' => ['roles'],
        'APPLICATION_DISCORD_RECONCILE' => ['application_reconcile'],
        'MEMBER_PROFILE_SYNC' => ['member_profile_sync'],
    ];

    public function __construct(
        private readonly FederationOperationGuard $federationGuard,
        private readonly AlertDeliveryReceiptService $receipts,
    ) {}

    /** @param list<DiscordQueueLane> $lanes */
    public function claim(
        string $workerId,
        string $requestId,
        array $lanes = [],
        ?string $guildId = null,
        ?DiscordConnectionContext $connection = null,
    ): ?DiscordQueue {
        $claimLanes = $lanes === []
            ? [DiscordQueueLane::Legacy, DiscordQueueLane::SideEffects]
            : $lanes;
        $heldExisting = false;

        try {
            $claimed = DB::transaction(function () use ($workerId, $requestId, $claimLanes, $guildId, $connection, &$heldExisting): ?DiscordQueue {
                $existingId = DiscordQueue::query()
                    ->where('claim_request_id', $requestId)
                    ->value('id');

                if ($existingId !== null) {
                    $operation = $this->lockOperationForQueueId((string) $existingId);
                    $existing = DiscordQueue::query()->lockForUpdate()->findOrFail($existingId);
                    $this->assertConnection($existing, $connection);

                    if ($this->hasActiveLease($existing)) {
                        if ($operation !== null && $this->federationGuard->isHeld($operation)) {
                            $this->suppressHeldCommand($existing);
                            $heldExisting = true;

                            return null;
                        }

                        return $existing;
                    }

                    throw new DiscordQueueLeaseException(
                        'claim_request_conflict',
                        'This claim request ID no longer identifies an active lease.',
                    );
                }

                while (true) {
                    $candidateId = DiscordQueue::query()
                        ->available(array_map(
                            fn (DiscordQueueLane $lane): string => $lane->value,
                            $claimLanes,
                        ))
                        ->when($guildId !== null, function (Builder $query) use ($guildId): void {
                            $query->where(function (Builder $guildQuery) use ($guildId): void {
                                $guildQuery->whereNull('guild_id')->orWhere('guild_id', $guildId);
                            });
                        })
                        ->when($connection !== null, function (Builder $query) use ($connection): void {
                            $query->where(function (Builder $binding) use ($connection): void {
                                $binding->where(function (Builder $current) use ($connection): void {
                                    $current
                                        ->where('connection_id', $connection->connectionId)
                                        ->where('application_id', $connection->applicationId)
                                        ->where('connection_generation', $connection->generation);
                                })->orWhere(function (Builder $legacy) use ($connection): void {
                                    $legacy->whereNull('connection_id')
                                        ->where(function (Builder $guild) use ($connection): void {
                                            $guild->whereNull('guild_id')->orWhere('guild_id', $connection->guildId);
                                        });
                                    });
                            });
                        })
                        ->value('id');

                    if ($candidateId === null) {
                        return null;
                    }

                    [$command, $operation] = $this->lockAvailableCandidate((string) $candidateId);

                    if ($command === null) {
                        continue;
                    }

                    if ($operation !== null && $this->federationGuard->isHeld($operation)) {
                        $this->suppressHeldCommand($command);

                        continue;
                    }

                    if ($connection !== null && $command->connection_id === null) {
                        $command->forceFill([
                            'connection_id' => $connection->connectionId,
                            'application_id' => $connection->applicationId,
                            'connection_generation' => $connection->generation,
                            'guild_id' => $command->guild_id ?? $connection->guildId,
                            'dedupe_scope' => $connection->dedupeScope(),
                        ])->save();
                    }

                    $command->forceFill([
                        'status' => DiscordQueueStatus::Processing,
                        'attempts' => $command->attempts + 1,
                        'claim_request_id' => $requestId,
                        'worker_id' => $workerId,
                        'lease_token' => (string) Str::uuid(),
                        'leased_until' => Carbon::now()->addMinutes(self::LEASE_MINUTES),
                        'last_error' => null,
                    ])->save();

                    return $command->fresh();
                }
            }, attempts: 3);

            if ($heldExisting) {
                throw $this->heldException();
            }

            if ($claimed !== null) {
                $this->receipts->beginAttempt($claimed);
            }

            return $claimed;
        } catch (QueryException $exception) {
            if (! $this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            $existing = DiscordQueue::query()
                ->where('claim_request_id', $requestId)
                ->first();

            if ($existing && $this->hasActiveLease($existing)) {
                $this->assertConnection($existing, $connection);
                $operation = $this->lockOperationForQueueId((string) $existing->getKey());
                if ($operation !== null && $this->federationGuard->isHeld($operation)) {
                    $this->suppressHeldCommand($existing);

                    throw $this->heldException();
                }

                $this->receipts->beginAttempt($existing);

                return $existing;
            }

            throw new DiscordQueueLeaseException(
                'claim_request_conflict',
                'This claim request ID no longer identifies an active lease.',
            );
        }
    }

    /**
     * Claim legacy batch work while applying the same federation hold gate as
     * the leased worker endpoint.
     *
     * @return EloquentCollection<int, DiscordQueue>
     */
    public function claimLegacyBatch(int $limit): EloquentCollection
    {
        return DB::transaction(function () use ($limit): EloquentCollection {
            $claimed = new EloquentCollection;
            $limit = max(1, $limit);

            while ($claimed->count() < $limit) {
                $candidateId = DiscordQueue::query()->available([
                    DiscordQueueLane::Legacy->value,
                    DiscordQueueLane::SideEffects->value,
                ])->value('id');

                if ($candidateId === null) {
                    break;
                }

                [$command, $operation] = $this->lockAvailableCandidate((string) $candidateId);

                if ($command === null) {
                    continue;
                }

                if ($operation !== null && $this->federationGuard->isHeld($operation)) {
                    $this->suppressHeldCommand($command);

                    continue;
                }

                $command->forceFill([
                    'status' => DiscordQueueStatus::Processing,
                    'attempts' => $command->attempts + 1,
                    'claim_request_id' => null,
                    'worker_id' => null,
                    'lease_token' => null,
                    'leased_until' => null,
                    'last_error' => null,
                ])->save();
                $claimed->push($command);
            }

            return $claimed;
        }, attempts: 3);
    }

    public function renew(
        DiscordQueue $command,
        string $leaseToken,
        ?DiscordConnectionContext $connection = null,
    ): DiscordQueue {
        $held = false;
        $renewed = DB::transaction(function () use ($command, $leaseToken, $connection, &$held): DiscordQueue {
            [$locked, $operation] = $this->lockCommandAndOperation($command);
            $this->assertConnection($locked, $connection);
            if ($this->suppressIfHeld($locked, $operation)) {
                $held = true;

                return $locked->fresh();
            }
            $this->assertActiveLease($locked, $leaseToken);

            $locked->forceFill([
                'leased_until' => Carbon::now()->addMinutes(self::LEASE_MINUTES),
            ])->save();

            return $locked->fresh();
        }, attempts: 3);

        if ($held) {
            throw $this->heldException();
        }

        return $renewed;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function checkpoint(
        DiscordQueue $command,
        string $leaseToken,
        array $result,
        ?DiscordConnectionContext $connection = null,
    ): DiscordQueue {
        $held = false;
        $checkpointed = DB::transaction(function () use ($command, $leaseToken, $result, $connection, &$held): DiscordQueue {
            [$locked, $operation] = $this->lockCommandAndOperation($command);
            $this->assertConnection($locked, $connection);
            if ($this->suppressIfHeld($locked, $operation)) {
                $held = true;

                return $locked->fresh();
            }
            $this->assertActiveLease($locked, $leaseToken);

            $allowedFields = self::CHECKPOINT_FIELDS[$locked->action] ?? [];

            if ($allowedFields === [] || array_diff(array_keys($result), $allowedFields) !== []) {
                throw new DiscordQueueLeaseException(
                    'checkpoint_not_supported',
                    'This queue action does not support the requested checkpoint fields.',
                    422,
                );
            }

            if ($locked->action === ApplicationDiscordReconciliationService::ACTION) {
                $this->applyApplicationReconcileCheckpoint(
                    $locked,
                    $result['application_reconcile'] ?? null,
                );
            }
            if ($locked->action === DiscordMemberProfileSyncService::ACTION) {
                $this->assertMemberProfileSyncCheckpoint(
                    $locked,
                    $result['member_profile_sync'] ?? null,
                );
            }

            $locked->forceFill([
                'result' => array_replace($locked->result ?? [], $result),
            ])->save();

            return $locked->fresh();
        }, attempts: 3);

        if ($held) {
            throw $this->heldException();
        }

        return $checkpointed;
    }

    private function applyApplicationReconcileCheckpoint(
        DiscordQueue $command,
        mixed $checkpoint,
    ): void {
        if (! is_array($checkpoint)) {
            throw new DiscordQueueLeaseException(
                'application_reconcile_checkpoint_invalid',
                'The application reconciliation checkpoint is invalid.',
                422,
            );
        }

        $payload = $command->payload;
        $applicationId = data_get($payload, 'application.id');
        $revision = data_get($payload, 'application.revision');
        if (! is_int($applicationId) || $applicationId < 1 || ! is_int($revision) || $revision < 1) {
            throw new DiscordQueueLeaseException(
                'application_reconcile_payload_invalid',
                'The queued application reconciliation contract is invalid.',
                409,
            );
        }

        $application = Application::query()->whereKey($applicationId)->lockForUpdate()->first();
        if (! $application
            || ! is_string($application->discord_reconcile_queue_id)
            || ! hash_equals($application->discord_reconcile_queue_id, (string) $command->getKey())
            || (int) $application->discord_reconcile_revision !== $revision
            || ($checkpoint['application_revision'] ?? null) !== $revision
            || strtolower($application->status->value) !== data_get($payload, 'application.state')
            || (int) $application->nation_id !== data_get($payload, 'application.nation_id')
            || ! hash_equals((string) $application->discord_user_id, (string) data_get($payload, 'application.discord_user_id'))) {
            throw new DiscordQueueLeaseException(
                'stale_application_reconciliation',
                'A newer application state superseded this reconciliation revision.',
                409,
            );
        }

        $bindings = [
            [$application->discord_connection_id, $command->connection_id, data_get($payload, 'installation.connection_id')],
            [$application->discord_application_id, $command->application_id, data_get($payload, 'installation.application_id')],
            [$application->discord_guild_id, $command->guild_id, data_get($payload, 'installation.guild_id')],
        ];
        foreach ($bindings as [$applicationValue, $queueValue, $payloadValue]) {
            if (! is_string($applicationValue)
                || ! is_string($queueValue)
                || ! is_string($payloadValue)
                || ! hash_equals($applicationValue, $queueValue)
                || ! hash_equals($applicationValue, $payloadValue)) {
                throw new DiscordQueueLeaseException(
                    'application_reconcile_binding_mismatch',
                    'The application reconciliation does not match its Discord installation.',
                    409,
                );
            }
        }
        if ((int) $application->discord_connection_generation !== (int) $command->connection_generation
            || (int) $application->discord_connection_generation !== data_get($payload, 'installation.generation')) {
            throw new DiscordQueueLeaseException(
                'stale_application_reconciliation',
                'A newer Discord connection generation superseded this reconciliation revision.',
                409,
            );
        }

        $this->assertApplicationCheckpointMatchesDesiredState($payload, $checkpoint);

        $channelId = $checkpoint['channel_id'];
        if ($checkpoint['channel_deleted'] === true) {
            $application->discord_channel_id = null;
        } elseif (is_string($channelId)) {
            $currentChannelId = trim((string) $application->discord_channel_id);
            if ($currentChannelId !== '' && ! hash_equals($currentChannelId, $channelId)) {
                throw new DiscordQueueLeaseException(
                    'application_channel_conflict',
                    'The application channel changed while Discord reconciliation was running.',
                    409,
                );
            }

            $application->discord_channel_id = $channelId;
        }

        if ($application->isDirty()) {
            $application->save();
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $checkpoint
     */
    private function assertApplicationCheckpointMatchesDesiredState(array $payload, array $checkpoint): void
    {
        $required = [
            'application_revision',
            'channel_id',
            'channel_deleted',
            'roles_added',
            'roles_removed',
            'intro_messages',
            'notifications',
        ];
        if (array_diff(array_keys($checkpoint), $required) !== []
            || array_diff($required, array_keys($checkpoint)) !== []
            || ! is_int($checkpoint['application_revision'])
            || ($checkpoint['channel_id'] !== null && ! is_string($checkpoint['channel_id']))
            || ! is_bool($checkpoint['channel_deleted'])
            || ! $this->isStringList($checkpoint['roles_added'])
            || ! $this->isStringList($checkpoint['roles_removed'])
            || ! $this->isStringList($checkpoint['intro_messages'])
            || ! $this->isStringList($checkpoint['notifications'])) {
            throw new DiscordQueueLeaseException(
                'application_reconcile_checkpoint_invalid',
                'The application reconciliation checkpoint is invalid.',
                422,
            );
        }

        $channelId = $checkpoint['channel_id'];
        $desiredMode = data_get($payload, 'desired.channel.mode');
        $desiredChannelId = data_get($payload, 'desired.channel.channel_id');
        if (($checkpoint['channel_deleted'] === true
                && ($desiredMode !== 'absent' || $channelId !== null))
            || ($desiredMode === 'absent' && $channelId !== null)
            || (is_string($channelId)
                && is_string($desiredChannelId)
                && ! hash_equals($desiredChannelId, $channelId))) {
            throw new DiscordQueueLeaseException(
                'application_reconcile_checkpoint_conflict',
                'The checkpoint does not match the desired application channel state.',
                409,
            );
        }

        $desiredAdds = data_get($payload, 'desired.roles.add', []);
        $desiredRemoves = data_get($payload, 'desired.roles.remove', []);
        $desiredIntroKeys = collect(data_get($payload, 'desired.channel.intro_messages', []))
            ->pluck('key')
            ->all();
        $desiredNotificationKeys = collect(data_get($payload, 'desired.notifications', []))
            ->pluck('key')
            ->all();
        if (! $this->isStringList($desiredAdds)
            || ! $this->isStringList($desiredRemoves)
            || ! $this->isStringList($desiredIntroKeys)
            || ! $this->isStringList($desiredNotificationKeys)
            || array_diff($checkpoint['roles_added'], $desiredAdds) !== []
            || array_diff($checkpoint['roles_removed'], $desiredRemoves) !== []
            || array_diff($checkpoint['intro_messages'], $desiredIntroKeys) !== []
            || array_diff($checkpoint['notifications'], $desiredNotificationKeys) !== []) {
            throw new DiscordQueueLeaseException(
                'application_reconcile_checkpoint_conflict',
                'The checkpoint contains Discord operations outside the Nexus desired state.',
                409,
            );
        }
    }

    private function isStringList(mixed $value): bool
    {
        return is_array($value)
            && array_is_list($value)
            && collect($value)->every(static fn (mixed $item): bool => is_string($item));
    }

    private function assertMemberProfileSyncCheckpoint(DiscordQueue $command, mixed $checkpoint): void
    {
        $required = ['profile_revision', 'nickname_applied', 'roles_added', 'roles_removed'];
        if (! is_array($checkpoint)
            || array_diff(array_keys($checkpoint), $required) !== []
            || array_diff($required, array_keys($checkpoint)) !== []
            || ! is_string($checkpoint['profile_revision'])
            || preg_match('/^[a-f0-9]{64}$/', $checkpoint['profile_revision']) !== 1
            || ! is_bool($checkpoint['nickname_applied'])
            || ! $this->isStringList($checkpoint['roles_added'])
            || ! $this->isStringList($checkpoint['roles_removed'])
            || count($checkpoint['roles_added']) !== count(array_unique($checkpoint['roles_added']))
            || count($checkpoint['roles_removed']) !== count(array_unique($checkpoint['roles_removed']))) {
            throw new DiscordQueueLeaseException(
                'member_profile_sync_checkpoint_invalid',
                'The member profile synchronization checkpoint is invalid.',
                422,
            );
        }

        $payload = $command->payload;
        $profileRevision = data_get($payload, 'member.profile_revision');
        $desiredAdds = data_get($payload, 'desired.roles.add', []);
        $desiredRemoves = data_get($payload, 'desired.roles.remove', []);
        if (! is_string($profileRevision)
            || ! hash_equals($profileRevision, $checkpoint['profile_revision'])
            || ! $this->isStringList($desiredAdds)
            || ! $this->isStringList($desiredRemoves)
            || array_diff($checkpoint['roles_added'], $desiredAdds) !== []
            || array_diff($checkpoint['roles_removed'], $desiredRemoves) !== []) {
            throw new DiscordQueueLeaseException(
                'member_profile_sync_checkpoint_conflict',
                'The checkpoint contains Discord operations outside the Nexus profile plan.',
                409,
            );
        }
    }

    public function acknowledge(
        DiscordQueue $command,
        DiscordQueueStatus $status,
        ?string $leaseToken,
        ?string $errorCode,
        ?string $errorMessage,
        ?array $result = null,
        ?DiscordConnectionContext $connection = null,
    ): DiscordQueue {
        $held = false;
        $acknowledged = DB::transaction(function () use ($command, $status, $leaseToken, $errorCode, $errorMessage, $result, $connection, &$held): DiscordQueue {
            [$locked, $operation] = $this->lockCommandAndOperation($command);
            $this->assertConnection($locked, $connection);
            if ($this->suppressIfHeld($locked, $operation)) {
                $held = true;

                return $locked->fresh();
            }

            if ($this->isIdempotentAcknowledgement($locked, $status, $leaseToken)) {
                return $locked;
            }

            $this->assertAcknowledgementAllowed($locked, $leaseToken);

            if ($status === DiscordQueueStatus::Complete) {
                $locked->forceFill([
                    'status' => DiscordQueueStatus::Complete,
                    'leased_until' => null,
                    'worker_id' => null,
                    'last_error' => null,
                    'result' => $result === null ? $locked->result : array_replace($locked->result ?? [], $result),
                    'completed_at' => Carbon::now(),
                ])->save();

                return $locked->fresh();
            }

            $retryable = $locked->action === 'ALERT_DELIVERY_V1'
                ? (bool) ($result['retryable'] ?? false)
                : true;
            $nextStatus = ! $retryable || $locked->attempts >= self::MAX_ATTEMPTS
                ? DiscordQueueStatus::Failed
                : DiscordQueueStatus::Pending;

            $retryAfterMilliseconds = max(0, (int) ($result['retry_after_ms'] ?? 0));
            $retryAt = $retryAfterMilliseconds > 0
                ? Carbon::now()->addMilliseconds(min($retryAfterMilliseconds, 30 * 60 * 1000))
                : Carbon::now()->addMinutes(max(1, $locked->attempts));

            $locked->forceFill([
                'status' => $nextStatus,
                'available_at' => $nextStatus === DiscordQueueStatus::Pending
                    ? $retryAt
                    : $locked->available_at,
                'leased_until' => null,
                'worker_id' => null,
                'last_error' => array_filter([
                    'code' => $errorCode,
                    'message' => $errorMessage,
                ], fn (?string $value): bool => $value !== null && $value !== ''),
                'completed_at' => null,
            ])->save();

            return $locked->fresh();
        }, attempts: 3);

        if ($held) {
            throw $this->heldException();
        }

        $this->receipts->record($acknowledged, $result ?? $acknowledged->result);

        return $acknowledged;
    }

    public function reapExpiredLeases(): int
    {
        $commands = DB::transaction(function () {
            $commandIds = DiscordQueue::query()
                ->where('status', DiscordQueueStatus::Processing->value)
                ->whereNotNull('lease_token')
                ->whereNotNull('leased_until')
                ->where('leased_until', '<=', Carbon::now())
                ->orderBy('leased_until')
                ->limit(100)
                ->pluck('id');
            $reaped = collect();

            foreach ($commandIds as $commandId) {
                $operation = $this->lockOperationForQueueId((string) $commandId);
                $command = DiscordQueue::query()->lockForUpdate()->find($commandId);

                if ($command === null
                    || $command->status !== DiscordQueueStatus::Processing
                    || $command->leased_until === null
                    || $command->leased_until->isFuture()) {
                    continue;
                }

                if ($operation !== null && $this->federationGuard->isHeld($operation)) {
                    $this->suppressHeldCommand($command);
                    $reaped->push($command->fresh());

                    continue;
                }

                $nextStatus = $command->attempts >= self::MAX_ATTEMPTS
                    ? DiscordQueueStatus::Failed
                    : DiscordQueueStatus::Pending;

                $command->forceFill([
                    'status' => $nextStatus,
                    'available_at' => $nextStatus === DiscordQueueStatus::Pending
                        ? Carbon::now()->addMinutes(max(1, $command->attempts))
                        : $command->available_at,
                    'leased_until' => null,
                    'lease_token' => null,
                    'worker_id' => null,
                    'last_error' => [
                        'code' => 'lease_expired',
                        'message' => 'The Discord worker lease expired before acknowledgement.',
                    ],
                    'completed_at' => null,
                ])->save();
                $reaped->push($command->fresh());
            }

            return $reaped;
        }, attempts: 3);

        $commands->each(function (DiscordQueue $command): void {
            $error = $command->last_error ?? [];

            $this->receipts->record($command, [
                'delivery' => 'failed',
                'error_code' => $error['code'] ?? 'lease_expired',
                'error_message' => $error['message'] ?? 'The Discord worker lease expired before acknowledgement.',
                'retryable' => $command->status === DiscordQueueStatus::Pending,
            ]);
        });

        return $commands->count();
    }

    public function hasActiveLease(DiscordQueue $command): bool
    {
        return $command->status === DiscordQueueStatus::Processing
            && $command->lease_token !== null
            && $command->leased_until !== null
            && $command->leased_until->isFuture();
    }

    /**
     * @return array{0: DiscordQueue, 1: MilcomOperation|null}
     */
    private function lockCommandAndOperation(DiscordQueue $command): array
    {
        $operation = $this->lockOperationForQueueId((string) $command->getKey());
        $locked = DiscordQueue::query()->lockForUpdate()->findOrFail($command->getKey());

        return [$locked, $operation];
    }

    /**
     * @return array{0: DiscordQueue|null, 1: MilcomOperation|null}
     */
    private function lockAvailableCandidate(string $candidateId): array
    {
        $operation = $this->lockOperationForQueueId($candidateId);
        $command = DiscordQueue::query()->lockForUpdate()->find($candidateId);

        if ($command === null || ! $this->isAvailable($command)) {
            return [null, null];
        }

        return [$command, $operation];
    }

    private function lockOperationForQueueId(string $queueId): ?MilcomOperation
    {
        $operationId = MilcomDispatch::query()
            ->where('queue_id', $queueId)
            ->value('operation_id');

        if ($operationId === null) {
            return null;
        }

        return MilcomOperation::query()->lockForUpdate()->find($operationId);
    }

    private function isAvailable(DiscordQueue $command): bool
    {
        return $command->status === DiscordQueueStatus::Pending
            && $command->attempts < self::MAX_ATTEMPTS
            && $command->available_at !== null
            && $command->available_at->lte(Carbon::now());
    }

    private function suppressIfHeld(DiscordQueue $command, ?MilcomOperation $operation): bool
    {
        if ($operation === null || ! $this->federationGuard->isHeld($operation)) {
            return false;
        }

        $this->suppressHeldCommand($command);

        return true;
    }

    private function heldException(): DiscordQueueLeaseException
    {
        return new DiscordQueueLeaseException(
            FederationOperationGuard::HELD_ERROR_CODE,
            FederationOperationGuard::HELD_ERROR_MESSAGE,
        );
    }

    private function suppressHeldCommand(DiscordQueue $command): void
    {
        $command->forceFill([
            'status' => DiscordQueueStatus::Failed,
            'leased_until' => null,
            'worker_id' => null,
            'lease_token' => null,
            'last_error' => [
                'code' => FederationOperationGuard::HELD_ERROR_CODE,
                'message' => FederationOperationGuard::HELD_ERROR_MESSAGE,
            ],
            'completed_at' => now(),
        ])->save();
    }

    private function assertActiveLease(DiscordQueue $command, string $leaseToken): void
    {
        if (! $this->tokenMatches($command, $leaseToken) || ! $this->hasActiveLease($command)) {
            throw new DiscordQueueLeaseException(
                'lease_conflict',
                'The queue lease is missing, expired, or owned by another worker.',
            );
        }
    }

    private function assertConnection(
        DiscordQueue $command,
        ?DiscordConnectionContext $connection,
    ): void {
        if ($connection === null) {
            return;
        }

        if ($command->connection_id === null
            && $connection->isDedicated()
            && $connection->protocolVersion === 1) {
            return;
        }

        if (! is_string($command->connection_id)
            || ! hash_equals($connection->connectionId, $command->connection_id)
            || ! is_string($command->application_id)
            || ! hash_equals($connection->applicationId, $command->application_id)
            || (int) $command->connection_generation !== $connection->generation
            || ($command->guild_id !== null && ! hash_equals($connection->guildId, $command->guild_id))) {
            throw new DiscordQueueLeaseException(
                'stale_connection_generation',
                'The queue item is not bound to the active Discord connection generation.',
            );
        }
    }

    private function assertAcknowledgementAllowed(DiscordQueue $command, ?string $leaseToken): void
    {
        if ($command->lease_token === null) {
            if ($leaseToken !== null || $command->status !== DiscordQueueStatus::Processing) {
                throw new DiscordQueueLeaseException(
                    'lease_conflict',
                    'This legacy queue command is not currently processing.',
                );
            }

            return;
        }

        if ($leaseToken === null) {
            throw new DiscordQueueLeaseException(
                'lease_token_required',
                'A lease token is required for this queue command.',
            );
        }

        $this->assertActiveLease($command, $leaseToken);
    }

    private function isIdempotentAcknowledgement(
        DiscordQueue $command,
        DiscordQueueStatus $status,
        ?string $leaseToken,
    ): bool {
        if ($leaseToken === null && $command->lease_token === null) {
            return $status === DiscordQueueStatus::Complete
                ? $command->status === DiscordQueueStatus::Complete
                : in_array($command->status, [DiscordQueueStatus::Pending, DiscordQueueStatus::Failed], true);
        }

        if ($leaseToken === null || ! $this->tokenMatches($command, $leaseToken) || $command->leased_until !== null) {
            return false;
        }

        if ($status === DiscordQueueStatus::Complete) {
            return $command->status === DiscordQueueStatus::Complete;
        }

        return in_array($command->status, [DiscordQueueStatus::Pending, DiscordQueueStatus::Failed], true);
    }

    private function tokenMatches(DiscordQueue $command, string $leaseToken): bool
    {
        return $command->lease_token !== null && hash_equals($command->lease_token, $leaseToken);
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        return (string) ($exception->errorInfo[0] ?? '') === '23000';
    }
}
