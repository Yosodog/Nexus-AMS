<?php

namespace App\Services\Discord;

use App\Enums\DiscordQueueStatus;
use App\Exceptions\DiscordQueueLeaseException;
use App\Models\DiscordQueue;
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
    ];

    public function claim(string $workerId, string $requestId): ?DiscordQueue
    {
        try {
            return DB::transaction(function () use ($workerId, $requestId): ?DiscordQueue {
                $existing = DiscordQueue::query()
                    ->where('claim_request_id', $requestId)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    if ($this->hasActiveLease($existing)) {
                        return $existing;
                    }

                    throw new DiscordQueueLeaseException(
                        'claim_request_conflict',
                        'This claim request ID no longer identifies an active lease.',
                    );
                }

                $command = DiscordQueue::query()
                    ->available()
                    ->lockForUpdate()
                    ->first();

                if (! $command) {
                    return null;
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
            }, attempts: 3);
        } catch (QueryException $exception) {
            if (! $this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            $existing = DiscordQueue::query()
                ->where('claim_request_id', $requestId)
                ->first();

            if ($existing && $this->hasActiveLease($existing)) {
                return $existing;
            }

            throw new DiscordQueueLeaseException(
                'claim_request_conflict',
                'This claim request ID no longer identifies an active lease.',
            );
        }
    }

    public function renew(DiscordQueue $command, string $leaseToken): DiscordQueue
    {
        return DB::transaction(function () use ($command, $leaseToken): DiscordQueue {
            $locked = $this->lockCommand($command);
            $this->assertActiveLease($locked, $leaseToken);

            $locked->forceFill([
                'leased_until' => Carbon::now()->addMinutes(self::LEASE_MINUTES),
            ])->save();

            return $locked->fresh();
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function checkpoint(DiscordQueue $command, string $leaseToken, array $result): DiscordQueue
    {
        return DB::transaction(function () use ($command, $leaseToken, $result): DiscordQueue {
            $locked = $this->lockCommand($command);
            $this->assertActiveLease($locked, $leaseToken);

            $allowedFields = self::CHECKPOINT_FIELDS[$locked->action] ?? [];

            if ($allowedFields === [] || array_diff(array_keys($result), $allowedFields) !== []) {
                throw new DiscordQueueLeaseException(
                    'checkpoint_not_supported',
                    'This queue action does not support the requested checkpoint fields.',
                    422,
                );
            }

            $locked->forceFill([
                'result' => array_replace($locked->result ?? [], $result),
            ])->save();

            return $locked->fresh();
        }, attempts: 3);
    }

    public function acknowledge(
        DiscordQueue $command,
        DiscordQueueStatus $status,
        ?string $leaseToken,
        ?string $errorCode,
        ?string $errorMessage,
        ?array $result = null,
    ): DiscordQueue {
        return DB::transaction(function () use ($command, $status, $leaseToken, $errorCode, $errorMessage, $result): DiscordQueue {
            $locked = $this->lockCommand($command);

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

            $nextStatus = $locked->attempts >= self::MAX_ATTEMPTS
                ? DiscordQueueStatus::Failed
                : DiscordQueueStatus::Pending;

            $locked->forceFill([
                'status' => $nextStatus,
                'available_at' => $nextStatus === DiscordQueueStatus::Pending
                    ? Carbon::now()->addMinutes(max(1, $locked->attempts))
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
    }

    public function reapExpiredLeases(): int
    {
        return DB::transaction(function (): int {
            $commands = DiscordQueue::query()
                ->where('status', DiscordQueueStatus::Processing->value)
                ->whereNotNull('lease_token')
                ->whereNotNull('leased_until')
                ->where('leased_until', '<=', Carbon::now())
                ->orderBy('leased_until')
                ->lockForUpdate()
                ->get();

            $commands->each(function (DiscordQueue $command): void {
                $nextStatus = $command->attempts >= self::MAX_ATTEMPTS
                    ? DiscordQueueStatus::Failed
                    : DiscordQueueStatus::Pending;

                $command->forceFill([
                    'status' => $nextStatus,
                    'available_at' => $nextStatus === DiscordQueueStatus::Pending
                        ? Carbon::now()->addMinutes(max(1, $command->attempts))
                        : $command->available_at,
                    'leased_until' => null,
                    'worker_id' => null,
                    'last_error' => [
                        'code' => 'lease_expired',
                        'message' => 'The Discord worker lease expired before acknowledgement.',
                    ],
                    'completed_at' => null,
                ])->save();
            });

            return $commands->count();
        }, attempts: 3);
    }

    public function hasActiveLease(DiscordQueue $command): bool
    {
        return $command->status === DiscordQueueStatus::Processing
            && $command->lease_token !== null
            && $command->leased_until !== null
            && $command->leased_until->isFuture();
    }

    private function lockCommand(DiscordQueue $command): DiscordQueue
    {
        return DiscordQueue::query()->lockForUpdate()->findOrFail($command->getKey());
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
