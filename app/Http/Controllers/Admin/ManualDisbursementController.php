<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SendManualCityGrantRequest;
use App\Http\Requests\Admin\SendManualGrantRequest;
use App\Http\Requests\Admin\SendManualLoanRequest;
use App\Http\Requests\Admin\SendManualWarAidRequest;
use App\Models\Account;
use App\Models\CityGrant;
use App\Models\CityGrantRequest;
use App\Models\GrantApplication;
use App\Models\Grants;
use App\Models\Loan;
use App\Models\ManualDisbursement;
use App\Models\Nation;
use App\Models\WarAidRequest;
use App\Services\AuditLogger;
use App\Services\CityCostService;
use App\Services\CityGrantService;
use App\Services\GrantService;
use App\Services\LoanService;
use App\Services\PWHelperService;
use App\Services\SelfApprovalGuard;
use App\Services\WarAidService;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class ManualDisbursementController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected LoanService $loanService,
        protected WarAidService $warAidService,
        protected SelfApprovalGuard $selfApprovalGuard,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function sendGrant(SendManualGrantRequest $request): RedirectResponse
    {
        $this->authorize('manage-grants');

        $data = $request->validated();

        $this->selfApprovalGuard->ensureNotSelf(
            requestNationId: (int) $data['nation_id'],
            context: 'send a grant to your own nation'
        );

        $grant = Grants::findOrFail($data['grant_id']);
        $nation = Nation::findOrFail($data['nation_id']);
        $account = $this->validateAccountForNation((int) $data['account_id'], $nation);

        try {
            /** @var GrantApplication $application */
            [$application, $created] = $this->executeOnce(
                (string) $data['idempotency_key'],
                ManualDisbursement::TYPE_GRANT,
                function () use ($account, $grant, $nation): GrantApplication {
                    $application = GrantApplication::create([
                        'grant_id' => $grant->id,
                        'nation_id' => $nation->id,
                        'account_id' => $account->id,
                        'status' => 'pending',
                        'pending_key' => 1,
                    ]);

                    GrantService::approveGrant($application);

                    return $application->refresh();
                }
            );
        } catch (ValidationException $exception) {
            $details = collect($exception->errors())->flatten()->implode(' ');

            return back()->with([
                'alert-message' => $details ?: 'Unable to send this grant manually.',
                'alert-type' => 'error',
            ]);
        } catch (QueryException $exception) {
            if ((string) ($exception->errorInfo[0] ?? '') === '23000') {
                return back()->with([
                    'alert-message' => 'This nation already has a pending application for that grant.',
                    'alert-type' => 'error',
                ]);
            }

            throw $exception;
        }

        if ($created) {
            $this->auditLogger->recordAfterCommit(
                category: 'grants',
                action: 'grant_disbursed_manual',
                outcome: 'success',
                severity: 'warning',
                subject: $application,
                context: [
                    'related' => [
                        ['type' => 'Grant', 'id' => (string) $grant->id, 'role' => 'grant'],
                        ['type' => 'Account', 'id' => (string) $account->id, 'role' => 'account'],
                    ],
                    'data' => [
                        'nation_id' => $nation->id,
                    ],
                ],
                message: 'Grant disbursed manually.'
            );
        }

        return back()
            ->with('alert-message', "Grant '{$grant->name}' sent manually — validation checks bypassed.")
            ->with('alert-type', 'success');
    }

    public function sendCityGrant(SendManualCityGrantRequest $request): RedirectResponse
    {
        $this->authorize('manage-city-grants');

        $data = $request->validated();

        $this->selfApprovalGuard->ensureNotSelf(
            requestNationId: (int) $data['nation_id'],
            context: 'send a city grant to your own nation'
        );

        $cityGrant = CityGrant::findOrFail($data['city_grant_id']);
        $nation = Nation::findOrFail($data['nation_id']);
        $account = $this->validateAccountForNation((int) $data['account_id'], $nation);

        $cityCostService = app(CityCostService::class);
        $cityNumber = (int) ($data['city_number'] ?? $cityGrant->city_number);
        $grantAmount = $data['grant_amount']
            ?? $cityCostService->calculateGrantAmountForCity(
                $cityNumber,
                $cityGrant->grant_amount,
                $cityCostService->grantRequiresBureauOfDomesticAffairs($cityGrant),
                $cityCostService->grantRequiresGovernmentSupportAgency($cityGrant)
            );

        if ($grantAmount === null) {
            return back()->with([
                'alert-message' => 'Unable to calculate the city grant amount right now. Please try again later.',
                'alert-type' => 'error',
            ]);
        }

        try {
            /** @var CityGrantRequest $grantRequest */
            [$grantRequest, $created] = $this->executeOnce(
                (string) $data['idempotency_key'],
                ManualDisbursement::TYPE_CITY_GRANT,
                function () use ($account, $cityNumber, $grantAmount, $nation): CityGrantRequest {
                    $grantRequest = CityGrantRequest::create([
                        'city_number' => $cityNumber,
                        'grant_amount' => (int) round($grantAmount),
                        'nation_id' => $nation->id,
                        'account_id' => $account->id,
                        'status' => 'pending',
                        'pending_key' => 1,
                    ]);

                    CityGrantService::approveGrant($grantRequest);

                    return $grantRequest->refresh();
                }
            );
        } catch (ValidationException $exception) {
            $details = collect($exception->errors())->flatten()->implode(' ');

            return back()->with([
                'alert-message' => $details ?: 'Unable to send this city grant manually.',
                'alert-type' => 'error',
            ]);
        } catch (QueryException $exception) {
            if ((string) ($exception->errorInfo[0] ?? '') === '23000') {
                return back()->with([
                    'alert-message' => 'This nation already has a pending city grant request.',
                    'alert-type' => 'error',
                ]);
            }

            throw $exception;
        }

        if ($created) {
            $this->auditLogger->recordAfterCommit(
                category: 'grants',
                action: 'city_grant_disbursed_manual',
                outcome: 'success',
                severity: 'warning',
                subject: $grantRequest,
                context: [
                    'related' => [
                        ['type' => 'CityGrant', 'id' => (string) $cityGrant->id, 'role' => 'grant'],
                        ['type' => 'Account', 'id' => (string) $account->id, 'role' => 'account'],
                    ],
                    'data' => [
                        'nation_id' => $nation->id,
                        'city_number' => $grantRequest->city_number,
                        'grant_amount' => $grantRequest->grant_amount,
                    ],
                ],
                message: 'City grant disbursed manually.'
            );
        }

        return back()
            ->with('alert-message', "City grant for City #{$grantRequest->city_number} sent manually — validation checks bypassed.")
            ->with('alert-type', 'success');
    }

    public function sendLoan(SendManualLoanRequest $request): RedirectResponse
    {
        $this->authorize('manage-loans');

        $data = $request->validated();

        $this->selfApprovalGuard->ensureNotSelf(
            requestNationId: (int) $data['nation_id'],
            context: 'approve or send a loan to your own nation'
        );

        $nation = Nation::findOrFail($data['nation_id']);
        $account = $this->validateAccountForNation((int) $data['account_id'], $nation);

        /** @var Loan $loan */
        [$loan, $created] = $this->executeOnce(
            (string) $data['idempotency_key'],
            ManualDisbursement::TYPE_LOAN,
            function () use ($account, $data, $nation): Loan {
                $loan = Loan::create([
                    'nation_id' => $nation->id,
                    'account_id' => $account->id,
                    'amount' => $data['amount'],
                    'remaining_balance' => $data['amount'],
                    'interest_rate' => $data['interest_rate'],
                    'term_weeks' => $data['term_weeks'],
                    'status' => 'pending',
                    'pending_key' => 1,
                ]);

                return $this->loanService->approveLoan(
                    $loan,
                    (float) $data['amount'],
                    (float) $data['interest_rate'],
                    (int) $data['term_weeks']
                );
            }
        );

        if ($created) {
            $this->auditLogger->recordAfterCommit(
                category: 'loans',
                action: 'loan_disbursed_manual',
                outcome: 'success',
                severity: 'warning',
                subject: $loan,
                context: [
                    'related' => [
                        ['type' => 'Account', 'id' => (string) $account->id, 'role' => 'account'],
                    ],
                    'data' => [
                        'nation_id' => $nation->id,
                        'amount' => $data['amount'],
                        'interest_rate' => $data['interest_rate'],
                        'term_weeks' => $data['term_weeks'],
                    ],
                ],
                message: 'Loan disbursed manually.'
            );
        }

        return back()
            ->with('alert-message', 'Loan created and approved manually — eligibility checks bypassed.')
            ->with('alert-type', 'success');
    }

    public function sendWarAid(SendManualWarAidRequest $request): RedirectResponse
    {
        $this->authorize('manage-war-aid');

        $data = $request->validated();

        $this->selfApprovalGuard->ensureNotSelf(
            requestNationId: (int) $data['nation_id'],
            context: 'send war aid to your own nation'
        );

        $resources = collect(PWHelperService::resources())
            ->mapWithKeys(fn ($resource) => [$resource => (int) ($data[$resource] ?? 0)])
            ->all();

        if (array_sum($resources) === 0) {
            throw ValidationException::withMessages([
                'money' => 'Provide at least one resource to send.',
            ]);
        }

        $nation = Nation::findOrFail($data['nation_id']);
        $account = $this->validateAccountForNation((int) $data['account_id'], $nation);
        $note = $data['note'] ?? 'Manual war aid disbursement';

        /** @var WarAidRequest $aidRequest */
        [$aidRequest, $created] = $this->executeOnce(
            (string) $data['idempotency_key'],
            ManualDisbursement::TYPE_WAR_AID,
            function () use ($account, $nation, $note, $resources): WarAidRequest {
                $aidRequest = WarAidRequest::create([
                    'nation_id' => $nation->id,
                    'account_id' => $account->id,
                    'note' => $note,
                    'status' => 'pending',
                    'pending_key' => 1,
                    ...$resources,
                ]);

                $this->warAidService->approveAidRequest($aidRequest, [
                    ...$resources,
                    'note' => $note,
                ]);

                return $aidRequest->refresh();
            }
        );

        if ($created) {
            $this->auditLogger->recordAfterCommit(
                category: 'war_aid',
                action: 'war_aid_disbursed_manual',
                outcome: 'success',
                severity: 'warning',
                subject: $aidRequest,
                context: [
                    'related' => [
                        ['type' => 'Account', 'id' => (string) $account->id, 'role' => 'account'],
                    ],
                    'data' => [
                        'nation_id' => $nation->id,
                        'resources' => $resources,
                        'note' => $note,
                    ],
                ],
                message: 'War aid disbursed manually.'
            );
        }

        return back()
            ->with('alert-message', 'War aid dispatched manually — request queue bypassed.')
            ->with('alert-type', 'success');
    }

    /**
     * @template TWorkflow of Model
     *
     * @param  Closure(): TWorkflow  $disburse
     * @return array{0: TWorkflow, 1: bool}
     */
    private function executeOnce(string $idempotencyKey, string $type, Closure $disburse): array
    {
        $actorId = (int) Auth::id();
        $existing = ManualDisbursement::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            return [$this->resolveExistingDisbursement($existing, $type, $actorId), false];
        }

        try {
            return DB::transaction(function () use ($actorId, $disburse, $idempotencyKey, $type): array {
                $existing = ManualDisbursement::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing) {
                    return [$this->resolveExistingDisbursement($existing, $type, $actorId), false];
                }

                $record = ManualDisbursement::query()->create([
                    'idempotency_key' => $idempotencyKey,
                    'type' => $type,
                    'created_by' => $actorId,
                ]);

                $workflow = $disburse();
                $workflow->refresh();

                if ($workflow->getAttribute('status') !== 'approved') {
                    throw ValidationException::withMessages([
                        'idempotency_key' => 'The manual disbursement was not approved.',
                    ]);
                }

                $record->update([
                    'workflow_id' => $workflow->getKey(),
                ]);

                return [$workflow, true];
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $exception) {
            $existing = ManualDisbursement::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if (! $existing) {
                throw $exception;
            }

            return [$this->resolveExistingDisbursement($existing, $type, $actorId), false];
        }
    }

    private function resolveExistingDisbursement(
        ManualDisbursement $record,
        string $type,
        int $actorId
    ): Model {
        if ($record->type !== $type || $record->created_by !== $actorId) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'This request identifier was already used for another manual disbursement.',
            ]);
        }

        if ($record->workflow_id === null) {
            throw new LogicException('Completed manual disbursement is missing its workflow record.');
        }

        $modelClass = match ($type) {
            ManualDisbursement::TYPE_GRANT => GrantApplication::class,
            ManualDisbursement::TYPE_CITY_GRANT => CityGrantRequest::class,
            ManualDisbursement::TYPE_LOAN => Loan::class,
            ManualDisbursement::TYPE_WAR_AID => WarAidRequest::class,
            default => throw new LogicException("Unsupported manual disbursement type [{$type}]."),
        };

        return $modelClass::query()->findOrFail($record->workflow_id);
    }

    protected function validateAccountForNation(int $accountId, Nation $nation): Account
    {
        $account = Account::where('id', $accountId)
            ->where('nation_id', $nation->id)
            ->first();

        if (! $account) {
            throw ValidationException::withMessages([
                'account_id' => 'Selected account does not belong to that nation.',
            ]);
        }

        return $account;
    }
}
