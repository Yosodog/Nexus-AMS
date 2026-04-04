<?php

namespace App\Services;

use App\DataTransferObjects\AllianceFinanceData;
use App\Events\AllianceExpenseOccurred;
use App\Models\AllianceFinanceEntry;
use App\Models\Nation;
use App\Models\WarAidRequest;
use App\Notifications\WarAidNotification;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class WarAidService
{
    /**
     * @throws ValidationException
     */
    public function submitAidRequest(Nation $nation, array $data): WarAidRequest
    {
        $account = $nation->accounts()->find((int) $data['account_id']);

        if (! $account) {
            throw ValidationException::withMessages([
                'account_id' => 'You do not own the selected account.',
            ]);
        }

        // Validate alliance membership
        app(NationEligibilityValidator::class, ['nation' => $nation])->validateAllianceMembership();

        if (WarAidRequest::where('nation_id', $nation->id)->where('status', 'pending')->exists()) {
            throw ValidationException::withMessages([
                'pending' => 'You already have a pending war aid request.',
            ]);
        }

        try {
            $request = WarAidRequest::create(
                $this->normalizeAidRequestData([
                    ...$data,
                    'nation_id' => $nation->id,
                    'pending_key' => 1,
                ])
            );
        } catch (QueryException $exception) {
            if ($this->isUniqueConstraintViolation($exception)) {
                throw ValidationException::withMessages([
                    'pending' => 'You already have a pending war aid request.',
                ]);
            }

            throw $exception;
        }

        app(PendingRequestsService::class)->flushCache();

        app(AuditLogger::class)->recordAfterCommit(
            category: 'war_aid',
            action: 'war_aid_request_submitted',
            outcome: 'success',
            severity: 'info',
            subject: $request,
            context: [
                'related' => [
                    ['type' => 'Account', 'id' => (string) $request->account_id, 'role' => 'account'],
                ],
                'data' => [
                    'nation_id' => $request->nation_id,
                    'resources' => $this->extractResources($data),
                ],
            ],
            message: 'War aid request submitted.'
        );

        return $request;
    }

    public function approveAidRequest(WarAidRequest $request, array $adjusted): void
    {
        app(SelfApprovalGuard::class)->ensureNotSelf(
            requestNationId: $request->nation_id,
            context: 'approve your own war aid request'
        );

        $resources = $this->extractResources($adjusted);

        $updatedRequest = DB::transaction(function () use ($request, $adjusted, $resources) {
            $lockedRequest = WarAidRequest::query()
                ->whereKey($request->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedRequest->status !== 'pending') {
                throw ValidationException::withMessages([
                    'request' => 'Only pending war aid requests can be approved.',
                ]);
            }

            $lockedRequest->update([
                ...$adjusted,
                'status' => 'approved',
                'pending_key' => null,
                'approved_at' => now(),
            ]);

            AccountService::adjustAccountBalance(
                $lockedRequest->account,
                [
                    ...$resources,
                    'note' => 'Approved war aid request ID #'.$lockedRequest->id,
                ],
                adminId: auth()->id(),
                ipAddress: request()->ip()
            );

            $lockedRequest->nation->notify(
                new WarAidNotification(
                    nation_id: $lockedRequest->nation_id,
                    request: $lockedRequest,
                    status: 'approved'
                )
            );

            return $lockedRequest->fresh();
        });

        if ($updatedRequest) {
            app(AuditLogger::class)->recordAfterCommit(
                category: 'war_aid',
                action: 'war_aid_approved',
                outcome: 'success',
                severity: 'info',
                subject: $updatedRequest,
                context: [
                    'related' => [
                        ['type' => 'Account', 'id' => (string) $updatedRequest->account_id, 'role' => 'account'],
                    ],
                    'data' => [
                        'nation_id' => $updatedRequest->nation_id,
                        'resources' => $resources,
                    ],
                ],
                message: 'War aid approved.'
            );

            $this->dispatchWarAidExpenseEvent($updatedRequest, $resources);
        }

        app(PendingRequestsService::class)->flushCache();
    }

    /**
     * @return array<int|string, mixed>
     */
    private function extractResources(array $data): array
    {
        return collect(PWHelperService::resources())
            ->mapWithKeys(fn ($res) => [$res => $data[$res] ?? 0])
            ->all();
    }

    /**
     * @return array<int|string, mixed>
     */
    private function normalizeAidRequestData(array $data): array
    {
        $resources = collect(PWHelperService::resources())
            ->mapWithKeys(fn ($resource) => [$resource => (int) ($data[$resource] ?? 0)])
            ->all();

        return [
            ...$data,
            ...$resources,
        ];
    }

    public function denyAidRequest(WarAidRequest $request): void
    {
        app(SelfApprovalGuard::class)->ensureNotSelf(
            requestNationId: $request->nation_id,
            context: 'deny your own war aid request'
        );

        $updatedRequest = DB::transaction(function () use ($request) {
            $lockedRequest = WarAidRequest::query()
                ->whereKey($request->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedRequest->status !== 'pending') {
                throw ValidationException::withMessages([
                    'request' => 'Only pending war aid requests can be denied.',
                ]);
            }

            $lockedRequest->update([
                'status' => 'denied',
                'pending_key' => null,
                'denied_at' => now(),
            ]);

            return $lockedRequest->fresh();
        });

        $updatedRequest->nation->notify(
            new WarAidNotification(
                nation_id: $updatedRequest->nation_id,
                request: $updatedRequest,
                status: 'denied'
            )
        );

        app(PendingRequestsService::class)->flushCache();

        app(AuditLogger::class)->recordAfterCommit(
            category: 'war_aid',
            action: 'war_aid_denied',
            outcome: 'denied',
            severity: 'warning',
            subject: $updatedRequest,
            context: [
                'related' => [
                    ['type' => 'Account', 'id' => (string) $updatedRequest->account_id, 'role' => 'account'],
                ],
                'data' => [
                    'nation_id' => $updatedRequest->nation_id,
                ],
            ],
            message: 'War aid denied.'
        );
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        return (string) ($exception->errorInfo[0] ?? '') === '23000';
    }

    /**
     * @return array<int|string, mixed>
     */
    public function getNationAvailableResources(Nation $nation): array
    {
        try {
            $live = [];

            foreach ($nation->accounts as $account) {
                foreach (PWHelperService::resources(false, false, true) as $resource) {
                    $live[$resource] = ($live[$resource] ?? 0) + ($account->$resource ?? 0);
                }
            }

            foreach (PWHelperService::resources(false, false, true) as $resource) {
                $live[$resource] = ($live[$resource] ?? 0) + ($nation->resources->$resource ?? 0);
            }

            return $live;
        } catch (Throwable $e) {
            return optional($nation->signIns()->latest()->first())->resources ?? [];
        }
    }

    private function dispatchWarAidExpenseEvent(WarAidRequest $request, array $resources): void
    {
        $financeData = new AllianceFinanceData(
            direction: AllianceFinanceEntry::DIRECTION_EXPENSE,
            category: 'war_aid',
            description: "War aid approved for Nation #{$request->nation_id}",
            date: now(),
            nationId: $request->nation_id,
            accountId: $request->account_id,
            source: $request,
            money: (float) ($resources['money'] ?? 0.0),
            coal: (float) ($resources['coal'] ?? 0.0),
            oil: (float) ($resources['oil'] ?? 0.0),
            uranium: (float) ($resources['uranium'] ?? 0.0),
            iron: (float) ($resources['iron'] ?? 0.0),
            bauxite: (float) ($resources['bauxite'] ?? 0.0),
            lead: (float) ($resources['lead'] ?? 0.0),
            gasoline: (float) ($resources['gasoline'] ?? 0.0),
            munitions: (float) ($resources['munitions'] ?? 0.0),
            steel: (float) ($resources['steel'] ?? 0.0),
            aluminum: (float) ($resources['aluminum'] ?? 0.0),
            food: (float) ($resources['food'] ?? 0.0),
            meta: [
                'war_aid_request_id' => $request->id,
            ]
        );

        event(new AllianceExpenseOccurred($financeData->toArray()));
    }

    /**
     * Count war aid requests pending review.
     */
    public function countPending(): int
    {
        return WarAidRequest::where('status', 'pending')->count();
    }
}
