<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\CityGrant;
use App\Models\CityGrantRequest;
use App\Models\GrantApplication;
use App\Models\Grants;
use App\Models\Loan;
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
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ManualDisbursementController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected LoanService $loanService,
        protected WarAidService $warAidService,
        protected SelfApprovalGuard $selfApprovalGuard,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function sendGrant(Request $request): RedirectResponse
    {
        $this->authorize('manage-grants');

        $data = $request->validate([
            'grant_id' => 'required|exists:grants,id',
            'nation_id' => 'required|exists:nations,id',
            'account_id' => 'required|exists:accounts,id',
        ]);

        $this->selfApprovalGuard->ensureNotSelf(
            requestNationId: (int) $data['nation_id'],
            context: 'send a grant to your own nation'
        );

        $grant = Grants::findOrFail($data['grant_id']);
        $nation = Nation::findOrFail($data['nation_id']);
        $account = $this->validateAccountForNation((int) $data['account_id'], $nation);

        $application = GrantApplication::create([
            'grant_id' => $grant->id,
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'status' => 'pending',
            'pending_key' => 1,
        ]);

        try {
            GrantService::approveGrant($application->fresh());
        } catch (ValidationException $exception) {
            $details = collect($exception->errors())->flatten()->implode(' ');

            return back()->with([
                'alert-message' => $details ?: 'Unable to send this grant manually.',
                'alert-type' => 'error',
            ]);
        }

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

        return back()
            ->with('alert-message', "Grant '{$grant->name}' sent manually — validation checks bypassed.")
            ->with('alert-type', 'success');
    }

    public function sendCityGrant(Request $request): RedirectResponse
    {
        $this->authorize('manage-city-grants');

        $data = $request->validate([
            'city_grant_id' => 'required|exists:city_grants,id',
            'nation_id' => 'required|exists:nations,id',
            'account_id' => 'required|exists:accounts,id',
            'city_number' => 'nullable|integer|min:1',
            'grant_amount' => 'nullable|integer|min:1',
        ]);

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

        $grantRequest = CityGrantRequest::create([
            'city_number' => $cityNumber,
            'grant_amount' => (int) round($grantAmount),
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'status' => 'pending',
            'pending_key' => 1,
        ]);

        try {
            CityGrantService::approveGrant($grantRequest);
        } catch (ValidationException $exception) {
            $details = collect($exception->errors())->flatten()->implode(' ');

            return back()->with([
                'alert-message' => $details ?: 'Unable to send this city grant manually.',
                'alert-type' => 'error',
            ]);
        }

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

        return back()
            ->with('alert-message', "City grant for City #{$grantRequest->city_number} sent manually — validation checks bypassed.")
            ->with('alert-type', 'success');
    }

    public function sendLoan(Request $request): RedirectResponse
    {
        $this->authorize('manage-loans');

        $data = $request->validate([
            'nation_id' => 'required|exists:nations,id',
            'account_id' => 'required|exists:accounts,id',
            'amount' => 'required|numeric|min:1',
            'interest_rate' => 'required|numeric|min:0|max:100',
            'term_weeks' => 'required|integer|min:1|max:52',
        ]);

        $this->selfApprovalGuard->ensureNotSelf(
            requestNationId: (int) $data['nation_id'],
            context: 'approve or send a loan to your own nation'
        );

        $nation = Nation::findOrFail($data['nation_id']);
        $account = $this->validateAccountForNation((int) $data['account_id'], $nation);

        $loan = Loan::create([
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'amount' => $data['amount'],
            'remaining_balance' => $data['amount'],
            'interest_rate' => $data['interest_rate'],
            'term_weeks' => $data['term_weeks'],
            'status' => 'pending',
        ]);

        $this->loanService->approveLoan($loan, $data['amount'], $data['interest_rate'], $data['term_weeks']);

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

        return back()
            ->with('alert-message', 'Loan created and approved manually — eligibility checks bypassed.')
            ->with('alert-type', 'success');
    }

    public function sendWarAid(Request $request): RedirectResponse
    {
        $this->authorize('manage-war-aid');

        $resourceRules = collect(PWHelperService::resources())
            ->mapWithKeys(fn ($resource) => [$resource => ['nullable', 'integer', 'min:0']])
            ->toArray();

        $data = $request->validate([
            'nation_id' => 'required|exists:nations,id',
            'account_id' => 'required|exists:accounts,id',
            'note' => 'nullable|string|max:255',
            ...$resourceRules,
        ]);

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

        $aidRequest = WarAidRequest::create([
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'note' => $note,
            'status' => 'pending',
            ...$resources,
        ]);

        $this->warAidService->approveAidRequest($aidRequest, [
            ...$resources,
            'note' => $note,
        ]);

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

        return back()
            ->with('alert-message', 'War aid dispatched manually — request queue bypassed.')
            ->with('alert-type', 'success');
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
