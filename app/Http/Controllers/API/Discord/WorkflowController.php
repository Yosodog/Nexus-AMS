<?php

namespace App\Http\Controllers\API\Discord;

use App\Http\Controllers\API\Discord\Concerns\DiscordApiResponses;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\CityGrant;
use App\Models\CityGrantRequest;
use App\Models\DiscordAccount;
use App\Models\GrantApplication;
use App\Models\Grants;
use App\Models\Loan;
use App\Models\MemberTransfer;
use App\Models\RebuildingRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WarAidRequest;
use App\Services\CityCostService;
use App\Services\CityGrantService;
use App\Services\Discord\DiscordLoanIntentService;
use App\Services\Discord\DiscordWorkflowIntentService;
use App\Services\GrantRequirementService;
use App\Services\GrantService;
use App\Services\LoanService;
use App\Services\PWHelperService;
use App\Services\RebuildingService;
use App\Services\SettingService;
use App\Services\WarAidService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WorkflowController extends Controller
{
    use DiscordApiResponses;

    public function requests(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['nullable', Rule::in(['grant', 'city_grant', 'loan', 'war_aid', 'rebuilding', 'withdrawal', 'member_transfer', 'application'])],
            'status' => ['nullable', Rule::in(['open', 'closed', 'needs-attention'])],
        ]);
        $actor = $this->actor($request);
        $type = $validated['type'] ?? null;
        $status = $validated['status'] ?? null;
        $items = collect();

        foreach ($this->requestSources($actor) as $sourceType => $query) {
            if ($type !== null && $sourceType !== $type) {
                continue;
            }

            if ($status !== null) {
                $this->applyRequestStatus($query, $sourceType, $status);
            }

            $items->push(...$query->latest()->limit(50)->get()->map(fn ($model): array => $this->requestSummary($sourceType, $model)));
        }

        return $this->discordData($items->sortByDesc('created_at')->values()->all());
    }

    public function grants(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'eligible_only' => ['nullable', 'boolean'],
            'query' => ['nullable', 'string', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $nation = $this->actor($request)->nation;
        $grants = Grants::query()
            ->where('is_enabled', true)
            ->when(isset($filters['query']), fn ($query) => $query->where('name', 'like', '%'.addcslashes($filters['query'], '%_\\').'%'))
            ->orderBy('name')
            ->limit((int) ($filters['limit'] ?? 100))
            ->get();
        $requirementService = app(GrantRequirementService::class);

        $available = $grants->map(function (Grants $grant) use ($nation, $requirementService): array {
            $inspection = $requirementService->inspect($grant->validation_rules ?? []);
            $report = $inspection['errors'] === []
                ? $requirementService->evaluate($inspection['normalized'], $nation)
                : ['passes' => false, 'failures' => ['Grant configuration is unavailable.'], 'summary' => []];

            return [
                'id' => $grant->id,
                'name' => $grant->name,
                'description' => $grant->description,
                'one_time' => $grant->is_one_time,
                'eligible' => (bool) $report['passes'],
                'eligibility_summary' => $report['summary'],
                'deep_link_path' => '/grants/'.$grant->slug,
            ];
        })->when($filters['eligible_only'] ?? false, fn ($items) => $items->where('eligible', true))->values();

        return $this->discordData([
            'available' => $available,
            'requests' => GrantApplication::query()->where('nation_id', $nation->id)->latest()->limit(50)->get()
                ->map(fn (GrantApplication $application): array => $this->requestSummary('grant', $application)),
        ]);
    }

    public function previewGrant(Request $request, DiscordWorkflowIntentService $intents): JsonResponse
    {
        $data = $request->validate([
            'grant_id' => ['required', 'integer', 'exists:grants,id'],
            'account_id' => ['required', 'integer'],
        ]);
        $user = $this->actor($request);
        $grant = Grants::query()->findOrFail($data['grant_id']);

        if (! $user->nation->accounts()->whereKey($data['account_id'])->exists()) {
            return $this->discordError('account_not_owned', 'The selected account does not belong to this actor.', 403);
        }

        try {
            GrantService::validateEligibility($grant, $user->nation);
        } catch (ValidationException $exception) {
            return $this->discordError('grant_ineligible', 'This grant cannot be requested.', 422, $exception->errors());
        }

        $intent = $intents->create($user, $this->discordAccount($request), $this->guildId($request), $this->interactionId($request), 'grant.application', [
            'grant_id' => $grant->id,
            'account_id' => (int) $data['account_id'],
        ]);

        return $this->discordData([
            'intent' => $this->intentPayload($intent),
            'eligible' => true,
            'confirmation_required' => true,
        ], 201);
    }

    public function confirmGrant(Request $request, DiscordWorkflowIntentService $intents): JsonResponse
    {
        $data = $request->validate(['intent_id' => ['required', 'string', 'size:64']]);
        $actor = $this->actor($request);
        $application = $intents->consume(
            $actor,
            $this->guildId($request),
            $data['intent_id'],
            'grant.application',
            fn (array $payload) => GrantService::applyToGrant(
                Grants::query()->findOrFail($payload['grant_id']),
                $actor->nation,
                (int) $payload['account_id'],
            ),
        );

        return $this->discordData($this->requestSummary('grant', $application), 201);
    }

    public function previewCityGrant(Request $request, CityCostService $costService, DiscordWorkflowIntentService $intents): JsonResponse
    {
        $data = $request->validate(['account_id' => ['required', 'integer']]);
        $nation = $this->actor($request)->nation;
        if (! $nation->accounts()->whereKey($data['account_id'])->exists()) {
            return $this->discordError('account_not_owned', 'The selected account does not belong to this actor.', 403);
        }

        $grant = CityGrant::query()->where('city_number', $nation->num_cities + 1)->where('enabled', true)->first();
        if (! $grant) {
            return $this->discordError('city_grant_unavailable', 'No city grant is available for the next city.', 404);
        }

        try {
            CityGrantService::validateEligibility($grant, $nation);
        } catch (ValidationException $exception) {
            return $this->discordError('city_grant_ineligible', 'This city grant cannot be requested.', 422, $exception->errors());
        }

        $intent = $intents->create($this->actor($request), $this->discordAccount($request), $this->guildId($request), $this->interactionId($request), 'city_grant.request', [
            'city_grant_id' => $grant->id,
            'account_id' => (int) $data['account_id'],
            'city_number' => $grant->city_number,
        ]);

        return $this->discordData([
            'intent' => $this->intentPayload($intent),
            'city_grant_id' => $grant->id,
            'city_number' => $grant->city_number,
            'account_id' => (int) $data['account_id'],
            'estimated_grant_amount' => $costService->calculateGrantAmount($grant),
            'eligible' => true,
            'confirmation_required' => true,
        ], 201);
    }

    public function confirmCityGrant(Request $request, DiscordWorkflowIntentService $intents): JsonResponse
    {
        $data = $request->validate(['intent_id' => ['required', 'string', 'size:64']]);
        $actor = $this->actor($request);
        $cityRequest = $intents->consume($actor, $this->guildId($request), $data['intent_id'], 'city_grant.request', function (array $payload) use ($actor) {
            $grant = CityGrant::query()->findOrFail($payload['city_grant_id']);
            abort_unless((int) $grant->city_number === (int) $actor->nation->num_cities + 1, 409, 'The next city changed after preview.');

            return CityGrantService::createRequest($grant, $actor->nation, (int) $payload['account_id']);
        });

        return $this->discordData($this->requestSummary('city_grant', $cityRequest), 201);
    }

    public function warAid(Request $request): JsonResponse
    {
        $items = WarAidRequest::query()->where('nation_id', $this->actor($request)->nation_id)->latest()->limit(50)->get();

        return $this->discordData([
            'enabled' => SettingService::isWarAidEnabled(),
            'requests' => $items->map(fn (WarAidRequest $item): array => $this->requestSummary('war_aid', $item)),
        ]);
    }

    public function loans(Request $request, LoanService $service): JsonResponse
    {
        $loans = Loan::query()->where('nation_id', $this->actor($request)->nation_id)->with('account:id,name')->latest()->get();

        return $this->discordData([
            'applications_enabled' => SettingService::isLoanApplicationsEnabled(),
            'payments_enabled' => SettingService::isLoanPaymentsEnabled(),
            'loans' => $loans->map(fn (Loan $loan): array => $this->loanPayload($loan, $service)),
        ]);
    }

    public function previewLoanApplication(Request $request, DiscordLoanIntentService $intents): JsonResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:100000', 'decimal:0,2'],
            'term_weeks' => ['required', 'integer', 'between:1,52'],
        ]);
        $intent = $intents->previewApplication(
            $this->actor($request),
            $this->discordAccount($request),
            (string) $request->header('X-Discord-Guild-ID'),
            (string) $request->header('X-Discord-Interaction-ID'),
            (int) $data['account_id'],
            (float) $data['amount'],
            (int) $data['term_weeks'],
        );

        return $this->discordData(['intent' => $this->intentPayload($intent), 'confirmation_required' => true], 201);
    }

    public function confirmLoanApplication(Request $request, DiscordLoanIntentService $intents, LoanService $service): JsonResponse
    {
        $data = $request->validate(['intent_id' => ['required', 'string', 'size:64']]);
        $loan = $intents->confirmApplication($this->actor($request), $data['intent_id']);

        return $this->discordData(['loan' => $this->loanPayload($loan, $service)], 201);
    }

    public function previewLoanPayment(Request $request, DiscordLoanIntentService $intents): JsonResponse
    {
        $data = $request->validate([
            'loan_id' => ['required', 'integer'],
            'account_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:0.01', 'decimal:0,2'],
        ]);
        $preview = $intents->previewPayment(
            $this->actor($request),
            $this->discordAccount($request),
            (string) $request->header('X-Discord-Guild-ID'),
            (string) $request->header('X-Discord-Interaction-ID'),
            (int) $data['loan_id'],
            (int) $data['account_id'],
            (float) $data['amount'],
        );

        return $this->discordData([
            'intent' => $this->intentPayload($preview['intent']),
            'breakdown' => $preview['breakdown'],
            'confirmation_required' => true,
        ], 201);
    }

    public function confirmLoanPayment(Request $request, DiscordLoanIntentService $intents, LoanService $service): JsonResponse
    {
        $data = $request->validate(['intent_id' => ['required', 'string', 'size:64']]);
        $loan = $intents->confirmPayment($this->actor($request), $data['intent_id']);

        return $this->discordData(['loan' => $this->loanPayload($loan, $service)]);
    }

    public function draftWarAid(Request $request, DiscordWorkflowIntentService $intents): JsonResponse
    {
        $data = $this->validateWarAid($request);
        $actor = $this->actor($request);
        if (! $actor->nation->accounts()->whereKey($data['account_id'])->exists()) {
            return $this->discordError('account_not_owned', 'The selected account does not belong to this actor.', 403);
        }

        $intent = $intents->create($actor, $this->discordAccount($request), $this->guildId($request), $this->interactionId($request), 'war_aid.request', $data);

        return $this->discordData(['intent' => $this->intentPayload($intent)], 201);
    }

    public function reviewWarAid(Request $request, DiscordWorkflowIntentService $intents): JsonResponse
    {
        $data = $request->validate(['intent_id' => ['required', 'string', 'size:64']]);
        $intent = $intents->get($this->actor($request), $this->guildId($request), $data['intent_id'], 'war_aid.request');

        return $this->warAidIntentReview($intent);
    }

    public function confirmWarAid(Request $request, WarAidService $service, DiscordWorkflowIntentService $intents): JsonResponse
    {
        $data = $request->validate(['intent_id' => ['required', 'string', 'size:64']]);
        if (! SettingService::isWarAidEnabled()) {
            return $this->discordError('war_aid_disabled', 'War aid is currently disabled.', 403);
        }
        $actor = $this->actor($request);
        $aidRequest = $intents->consume(
            $actor,
            $this->guildId($request),
            $data['intent_id'],
            'war_aid.request',
            fn (array $payload) => $service->submitAidRequest($actor->nation, $payload),
        );

        return $this->discordData($this->requestSummary('war_aid', $aidRequest), 201);
    }

    public function rebuildingPreview(Request $request, RebuildingService $service): JsonResponse
    {
        $nation = $this->actor($request)->nation;
        $estimate = $service->buildNationEstimate($nation);

        return $this->discordData([
            'enabled' => SettingService::isRebuildingEnabled(),
            'cycle_id' => $service->getCurrentCycleId(),
            'eligible' => $estimate['eligible'],
            'reason' => $estimate['reason'],
            'city_count' => $estimate['city_count'],
            'estimated_amount' => $estimate['amount'],
            'accounts' => $nation->accounts()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function rebuildingConfirm(Request $request, RebuildingService $service): JsonResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'integer'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $rebuildingRequest = $service->submitRequest($this->actor($request)->nation, $data);

        return $this->discordData($this->requestSummary('rebuilding', $rebuildingRequest), 201);
    }

    /** @return array<string, Builder> */
    private function requestSources(User $actor): array
    {
        $nationId = (int) $actor->nation_id;

        return [
            'grant' => GrantApplication::query()->where('nation_id', $nationId),
            'city_grant' => CityGrantRequest::query()->where('nation_id', $nationId),
            'loan' => Loan::query()->where('nation_id', $nationId),
            'war_aid' => WarAidRequest::query()->where('nation_id', $nationId),
            'rebuilding' => RebuildingRequest::query()->where('nation_id', $nationId),
            'withdrawal' => Transaction::query()->where('nation_id', $nationId)->where('transaction_type', 'withdrawal'),
            'member_transfer' => MemberTransfer::query()->where(fn ($query) => $query
                ->where('from_nation_id', $nationId)->orWhere('to_nation_id', $nationId)),
            'application' => Application::query()->where('nation_id', $nationId),
        ];
    }

    private function applyRequestStatus(Builder $query, string $type, string $status): void
    {
        if ($type === 'withdrawal') {
            match ($status) {
                'open' => $query->where('is_pending', true),
                'closed' => $query->where('is_pending', false),
                'needs-attention' => $query->where(fn ($nested) => $nested
                    ->where('requires_admin_approval', true)
                    ->orWhere('bank_attempt_status', Transaction::BANK_ATTEMPT_NEEDS_RECONCILIATION)),
            };

            return;
        }

        $pendingValue = $type === 'application' ? 'PENDING' : 'pending';
        match ($status) {
            'open' => $query->where('status', $pendingValue),
            'closed' => $query->where('status', '!=', $pendingValue),
            'needs-attention' => $type === 'loan'
                ? $query->whereIn('status', ['missed', 'past_due'])
                : $query->where('status', $pendingValue),
        };
    }

    private function requestSummary(string $type, object $model): array
    {
        return [
            'type' => $type,
            'id' => $model->id,
            'status' => $type === 'withdrawal'
                ? ($model->is_pending ? 'pending' : ($model->denied_at ? 'failed' : 'completed'))
                : (is_object($model->status) && property_exists($model->status, 'value') ? $model->status->value : $model->status),
            'created_at' => optional($model->created_at)->toIso8601String(),
            'updated_at' => optional($model->updated_at)->toIso8601String(),
            'deep_link_path' => match ($type) {
                'grant' => '/grants',
                'city_grant' => '/grants/city',
                'loan' => '/loans',
                'war_aid' => '/defense/waraid',
                'rebuilding' => '/defense/rebuilding',
                'withdrawal' => '/accounts',
                'member_transfer' => '/accounts',
                'application' => '/apply',
            },
        ];
    }

    private function warAidIntentReview(object $intent): JsonResponse
    {
        return $this->discordData([
            'intent' => $this->intentPayload($intent),
            'valid' => true,
            'account_id' => (int) $intent->payload['account_id'],
            'note' => $intent->payload['note'],
            'resources' => collect(PWHelperService::resources())->mapWithKeys(fn (string $resource): array => [$resource => (int) ($intent->payload[$resource] ?? 0)]),
            'confirmation_required' => true,
        ]);
    }

    private function validateWarAid(Request $request): array
    {
        return $request->validate([
            'account_id' => ['required', 'integer'],
            'note' => ['required', 'string', 'max:255'],
            ...collect(PWHelperService::resources())->mapWithKeys(
                fn (string $resource): array => [$resource => ['nullable', 'integer', 'min:0']]
            )->all(),
        ]);
    }

    private function actor(Request $request): User
    {
        $actor = $request->attributes->get('discord_actor');
        abort_unless($actor instanceof User && $actor->nation !== null, 401, 'Discord actor context is missing.');

        return $actor;
    }

    private function discordAccount(Request $request): DiscordAccount
    {
        $account = $request->attributes->get('discord_account');
        abort_unless($account instanceof DiscordAccount, 401, 'Discord account context is missing.');

        return $account;
    }

    private function intentPayload(object $intent): array
    {
        return [
            'id' => $intent->presentedToken,
            'action' => $intent->action,
            'status' => $intent->status,
            'expires_at' => $intent->expires_at->toIso8601String(),
        ];
    }

    private function guildId(Request $request): string
    {
        return (string) $request->header('X-Discord-Guild-ID');
    }

    private function interactionId(Request $request): string
    {
        return (string) $request->header('X-Discord-Interaction-ID');
    }

    private function loanPayload(Loan $loan, LoanService $service): array
    {
        return [
            'id' => $loan->id,
            'account_id' => $loan->account_id,
            'status' => $loan->status,
            'amount' => (float) $loan->amount,
            'remaining_balance' => (float) $loan->remaining_balance,
            'interest_rate' => $loan->interest_rate === null ? null : (float) $loan->interest_rate,
            'term_weeks' => (int) $loan->term_weeks,
            'scheduled_weekly_payment' => (float) $loan->scheduled_weekly_payment,
            'current_amount_due' => $service->calculateCurrentAmountDue($loan),
            'next_due_date' => optional($loan->next_due_date)->toDateString(),
            'created_at' => optional($loan->created_at)->toIso8601String(),
            'deep_link_path' => '/loans',
        ];
    }
}
