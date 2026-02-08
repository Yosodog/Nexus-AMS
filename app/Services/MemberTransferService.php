<?php

namespace App\Services;

use App\Exceptions\UserErrorException;
use App\Models\Account;
use App\Models\MemberTransfer;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\DB;

class MemberTransferService
{
    public function __construct(private readonly AllianceMembershipService $membershipService) {}

    /**
     * @throws Exception
     */
    public function requestTransfer(User $user, int $fromAccountId, int $toAccountId, array $resources): MemberTransfer
    {
        $resources = $this->normalizeResources($resources);

        return DB::transaction(function () use ($user, $fromAccountId, $toAccountId, $resources): MemberTransfer {
            $fromAccount = Account::query()
                ->with('nation')
                ->lockForUpdate()
                ->findOrFail($fromAccountId);

            $toAccount = Account::query()
                ->with('nation')
                ->lockForUpdate()
                ->findOrFail($toAccountId);

            $this->validateRequest($user, $fromAccount, $toAccount, $resources);

            foreach (PWHelperService::resources() as $resource) {
                $fromAccount->{$resource} -= $resources[$resource];
            }

            $fromAccount->save();

            $transfer = MemberTransfer::create([
                'from_account_id' => $fromAccount->id,
                'to_account_id' => $toAccount->id,
                'from_nation_id' => $fromAccount->nation_id,
                'to_nation_id' => $toAccount->nation_id,
                'created_by' => $user->id,
                'status' => MemberTransfer::STATUS_PENDING,
                ...$resources,
            ]);

            app(AuditLogger::class)->recordAfterCommit(
                category: 'finance',
                action: 'member_transfer_requested',
                outcome: 'pending',
                severity: 'info',
                subject: $transfer,
                context: [
                    'related' => [
                        ['type' => 'Account', 'id' => (string) $fromAccount->id, 'role' => 'from_account'],
                        ['type' => 'Account', 'id' => (string) $toAccount->id, 'role' => 'to_account'],
                    ],
                    'data' => [
                        'from_nation_id' => $fromAccount->nation_id,
                        'to_nation_id' => $toAccount->nation_id,
                        'resources' => $resources,
                    ],
                ],
                message: 'Member transfer requested.'
            );

            return $transfer;
        });
    }

    /**
     * @throws Exception
     */
    public function acceptTransfer(User $user, MemberTransfer $transfer): MemberTransfer
    {
        $resources = $this->normalizeResources($transfer->toArray());

        return DB::transaction(function () use ($user, $transfer, $resources): MemberTransfer {
            $lockedTransfer = MemberTransfer::query()
                ->lockForUpdate()
                ->findOrFail($transfer->id);

            if ($lockedTransfer->status !== MemberTransfer::STATUS_PENDING) {
                throw new UserErrorException('This transfer is no longer pending.');
            }

            if ($lockedTransfer->to_nation_id !== $user->nation_id) {
                throw new UserErrorException('You are not allowed to accept this transfer.');
            }

            $toAccount = Account::query()
                ->lockForUpdate()
                ->findOrFail($lockedTransfer->to_account_id);

            if ($toAccount->frozen) {
                throw new UserErrorException('The destination account is frozen. Transfers are disabled.');
            }

            foreach (PWHelperService::resources() as $resource) {
                $toAccount->{$resource} += $resources[$resource];
            }

            $toAccount->save();

            $lockedTransfer->forceFill([
                'status' => MemberTransfer::STATUS_ACCEPTED,
                'accepted_at' => now(),
                'accepted_by' => $user->id,
            ])->save();

            TransactionService::createTransaction(
                $resources,
                $lockedTransfer->from_nation_id,
                $lockedTransfer->from_account_id,
                'member_transfer',
                $lockedTransfer->to_account_id,
                false,
                'Member transfer accepted.'
            );

            app(AuditLogger::class)->recordAfterCommit(
                category: 'finance',
                action: 'member_transfer_accepted',
                outcome: 'success',
                severity: 'info',
                subject: $lockedTransfer,
                context: [
                    'related' => [
                        ['type' => 'Account', 'id' => (string) $lockedTransfer->from_account_id, 'role' => 'from_account'],
                        ['type' => 'Account', 'id' => (string) $lockedTransfer->to_account_id, 'role' => 'to_account'],
                    ],
                    'data' => [
                        'from_nation_id' => $lockedTransfer->from_nation_id,
                        'to_nation_id' => $lockedTransfer->to_nation_id,
                        'resources' => $resources,
                    ],
                ],
                message: 'Member transfer accepted.'
            );

            return $lockedTransfer;
        });
    }

    /**
     * @throws Exception
     */
    public function declineTransfer(User $user, MemberTransfer $transfer): MemberTransfer
    {
        $resources = $this->normalizeResources($transfer->toArray());

        return DB::transaction(function () use ($user, $transfer, $resources): MemberTransfer {
            $lockedTransfer = MemberTransfer::query()
                ->lockForUpdate()
                ->findOrFail($transfer->id);

            if ($lockedTransfer->status !== MemberTransfer::STATUS_PENDING) {
                throw new UserErrorException('This transfer is no longer pending.');
            }

            if ($lockedTransfer->to_nation_id !== $user->nation_id) {
                throw new UserErrorException('You are not allowed to decline this transfer.');
            }

            $fromAccount = Account::query()
                ->lockForUpdate()
                ->findOrFail($lockedTransfer->from_account_id);

            foreach (PWHelperService::resources() as $resource) {
                $fromAccount->{$resource} += $resources[$resource];
            }

            $fromAccount->save();

            $lockedTransfer->forceFill([
                'status' => MemberTransfer::STATUS_DECLINED,
                'declined_at' => now(),
                'declined_by' => $user->id,
            ])->save();

            app(AuditLogger::class)->recordAfterCommit(
                category: 'finance',
                action: 'member_transfer_declined',
                outcome: 'success',
                severity: 'warning',
                subject: $lockedTransfer,
                context: [
                    'related' => [
                        ['type' => 'Account', 'id' => (string) $lockedTransfer->from_account_id, 'role' => 'from_account'],
                        ['type' => 'Account', 'id' => (string) $lockedTransfer->to_account_id, 'role' => 'to_account'],
                    ],
                    'data' => [
                        'from_nation_id' => $lockedTransfer->from_nation_id,
                        'to_nation_id' => $lockedTransfer->to_nation_id,
                        'resources' => $resources,
                    ],
                ],
                message: 'Member transfer declined.'
            );

            return $lockedTransfer;
        });
    }

    /**
     * @throws Exception
     */
    public function cancelTransfer(User $user, MemberTransfer $transfer, bool $isAdmin = false): MemberTransfer
    {
        $resources = $this->normalizeResources($transfer->toArray());

        return DB::transaction(function () use ($user, $transfer, $resources, $isAdmin): MemberTransfer {
            $lockedTransfer = MemberTransfer::query()
                ->lockForUpdate()
                ->findOrFail($transfer->id);

            if ($lockedTransfer->status !== MemberTransfer::STATUS_PENDING) {
                throw new UserErrorException('This transfer is no longer pending.');
            }

            if (! $isAdmin && $lockedTransfer->from_nation_id !== $user->nation_id) {
                throw new UserErrorException('You are not allowed to cancel this transfer.');
            }

            $fromAccount = Account::query()
                ->lockForUpdate()
                ->findOrFail($lockedTransfer->from_account_id);

            foreach (PWHelperService::resources() as $resource) {
                $fromAccount->{$resource} += $resources[$resource];
            }

            $fromAccount->save();

            $lockedTransfer->forceFill([
                'status' => MemberTransfer::STATUS_CANCELED,
                'canceled_at' => now(),
                'canceled_by' => $user->id,
            ])->save();

            app(AuditLogger::class)->recordAfterCommit(
                category: 'finance',
                action: 'member_transfer_canceled',
                outcome: 'success',
                severity: 'warning',
                subject: $lockedTransfer,
                context: [
                    'related' => [
                        ['type' => 'Account', 'id' => (string) $lockedTransfer->from_account_id, 'role' => 'from_account'],
                        ['type' => 'Account', 'id' => (string) $lockedTransfer->to_account_id, 'role' => 'to_account'],
                    ],
                    'data' => [
                        'from_nation_id' => $lockedTransfer->from_nation_id,
                        'to_nation_id' => $lockedTransfer->to_nation_id,
                        'resources' => $resources,
                        'admin_override' => $isAdmin,
                    ],
                ],
                message: 'Member transfer canceled.'
            );

            return $lockedTransfer;
        });
    }

    /**
     * @throws UserErrorException
     */
    private function validateRequest(User $user, Account $fromAccount, Account $toAccount, array $resources): void
    {
        if ($fromAccount->frozen) {
            throw new UserErrorException('This account is frozen. Transfers are disabled.');
        }

        if ($toAccount->frozen) {
            throw new UserErrorException('The destination account is frozen. Transfers are disabled.');
        }

        if ($fromAccount->nation_id !== $user->nation_id) {
            throw new UserErrorException('You do not own the source account.');
        }

        if ($fromAccount->id === $toAccount->id) {
            throw new UserErrorException('You cannot transfer to the same account.');
        }

        if ($toAccount->nation_id === $user->nation_id) {
            throw new UserErrorException('Use internal transfers for your own accounts.');
        }

        $fromAllianceId = $fromAccount->nation?->alliance_id;
        $toAllianceId = $toAccount->nation?->alliance_id;

        if (! $this->membershipService->contains($fromAllianceId) || ! $this->membershipService->contains($toAllianceId)) {
            throw new UserErrorException('Transfers are only allowed to members in your alliance.');
        }

        $hasResources = false;

        foreach (PWHelperService::resources() as $resource) {
            if ($resources[$resource] < 0) {
                throw new UserErrorException("{$resource} is set to a negative number.");
            }

            if ($resources[$resource] > $fromAccount->{$resource}) {
                throw new UserErrorException("Insufficient {$resource} in the source account.");
            }

            if ($resources[$resource] > 0) {
                $hasResources = true;
            }
        }

        if (! $hasResources) {
            throw new UserErrorException("You can't transfer nothing.");
        }
    }

    /**
     * @return array<string, float>
     */
    private function normalizeResources(array $resources): array
    {
        $normalized = [];

        foreach (PWHelperService::resources() as $resource) {
            $normalized[$resource] = (float) ($resources[$resource] ?? 0);
        }

        return $normalized;
    }
}
