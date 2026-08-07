<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\ApproveGrantApplicationRequest;
use App\Http\Requests\Admin\DenyGrantApplicationRequest;
use App\Http\Requests\Admin\StoreGrantRequest;
use App\Http\Requests\Admin\UpdateGrantRequest;
use App\Models\GrantApplication;
use App\Models\Grants;
use App\Services\AuditLogger;
use App\Services\GrantRequirementService;
use App\Services\GrantService;
use App\Services\PWHelperService;
use App\Services\SettingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GrantController
{
    use AuthorizesRequests;

    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly GrantRequirementService $grantRequirementService,
    ) {}

    /**
     * @return Factory|View|Application|object
     *
     * @throws AuthorizationException
     */
    public function grants()
    {
        $this->authorize('view-grants');

        $grants = Grants::orderBy('created_at', 'desc')->get()
            ->each(function (Grants $grant): void {
                $inspection = $this->grantRequirementService->inspect($grant->validation_rules);

                if ($inspection['errors'] !== []) {
                    Log::warning('Grant has malformed stored validation rules.', [
                        'grant_id' => $grant->id,
                        'errors' => $inspection['errors'],
                    ]);
                }

                $grant->validation_rules = $inspection['normalized'];
                $grant->setAttribute('requirement_summary', $this->buildRequirementSummary($inspection['normalized'], $inspection['errors']));
            });
        $pendingRequests = GrantApplication::with('grant', 'nation', 'account')
            ->where('status', 'pending')
            ->orderBy('created_at', 'desc')
            ->get();

        $canManageGrants = auth()->user()?->can('manage-grants') ?? false;
        $historyColumns = array_merge([
            'id',
            'grant_id',
            'program_name_snapshot',
            'program_version_snapshot',
            'nation_id',
            'account_id',
            'status',
            'decision_reason_code',
            'decision_explanation',
            'reviewed_by_user_id',
            'submitted_at',
            'approved_at',
            'denied_at',
            'decided_at',
            'disbursed_at',
            'created_at',
            'updated_at',
        ], GrantApplication::PAYOUT_COLUMNS);

        if ($canManageGrants) {
            $historyColumns[] = 'decision_internal_note';
        }

        $recentApplications = GrantApplication::query()
            ->with([
                'nation:id,leader_name,nation_name',
                'account:id,name',
                'reviewer:id,name',
            ])
            ->latest('created_at')
            ->paginate(50, $historyColumns, 'history_page');

        $totalApproved = GrantApplication::where('status', 'approved')->count();
        $totalDenied = GrantApplication::where('status', 'denied')->count();
        $pendingCount = $pendingRequests->count();
        $totalFundsDistributed = GrantApplication::where('status', 'approved')->sum('money');
        $grantApprovalsEnabled = SettingService::isGrantApprovalsEnabled();

        return view(
            'admin.grants.grants',
            compact(
                'grants',
                'pendingRequests',
                'recentApplications',
                'totalApproved',
                'totalDenied',
                'pendingCount',
                'totalFundsDistributed',
                'grantApprovalsEnabled'
            )
        )->with('grantRequirementBuilderConfig', $this->grantRequirementService->getBuilderConfig());
    }

    /**
     * @return RedirectResponse
     *
     * @throws AuthorizationException
     */
    public function createGrant(StoreGrantRequest $request)
    {
        $validated = $request->validated();

        $grant = new Grants;
        $grant->name = $validated['name'];
        $grant->slug = Str::slug($grant->name);
        $grant->description = $validated['description'];
        $grant->money = $validated['money'] ?? 0;

        foreach (PWHelperService::resources(false) as $resource) {
            $grant->$resource = $validated[$resource] ?? 0;
        }

        $grant->validation_rules = $this->grantRequirementService->normalize($validated['validation_rules'] ?? null);
        $grant->is_one_time = filter_var($validated['is_one_time'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $grant->is_enabled = filter_var($validated['is_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $grant->save();

        $this->auditLogger->success(
            category: 'grants',
            action: 'grant_created',
            subject: $grant,
            context: [
                'data' => $grant->only(['name', 'slug', 'description', 'money', 'is_one_time', 'is_enabled', 'validation_rules']),
            ],
            message: 'Grant created.'
        );

        return redirect()->route('admin.grants')
            ->with('alert-message', 'Grant created successfully.')
            ->with('alert-type', 'success');
    }

    /**
     * @return RedirectResponse
     *
     * @throws AuthorizationException
     */
    public function updateGrant(Grants $grant, UpdateGrantRequest $request)
    {
        $validated = $request->validated();
        $versionedFields = array_merge(
            ['name', 'description', 'money', 'is_one_time', 'is_enabled', 'validation_rules'],
            PWHelperService::resources(false),
        );
        $before = $grant->only(array_merge($versionedFields, ['version']));

        $grant->name = $validated['name'];
        $grant->slug = Str::slug($grant->name);
        $grant->description = $validated['description'];
        $grant->money = $validated['money'] ?? 0;

        foreach (PWHelperService::resources(false) as $resource) {
            $grant->$resource = $validated[$resource] ?? 0;
        }

        $grant->validation_rules = $this->grantRequirementService->normalize($validated['validation_rules'] ?? null);
        $grant->is_one_time = filter_var($validated['is_one_time'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $grant->is_enabled = filter_var($validated['is_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($grant->isDirty($versionedFields)) {
            $grant->version = max(1, (int) $grant->version) + 1;
        }

        $grant->save();

        $after = $grant->only(array_merge($versionedFields, ['version']));
        $changes = [];

        foreach ($after as $field => $value) {
            if ($this->valuesDiffer($before[$field] ?? null, $value)) {
                $changes[$field] = [
                    'from' => $before[$field] ?? null,
                    'to' => $value,
                ];
            }
        }

        $this->auditLogger->recordAfterCommit(
            category: 'grants',
            action: 'grant_updated',
            outcome: 'success',
            severity: 'warning',
            subject: $grant,
            context: [
                'changes' => $changes,
            ],
            message: 'Grant updated.'
        );

        return redirect()->route('admin.grants')
            ->with('alert-message', 'Grant updated successfully.')
            ->with('alert-type', 'success');
    }

    private function valuesDiffer(mixed $before, mixed $after): bool
    {
        return $this->normalizeComparableValue($before) !== $this->normalizeComparableValue($after);
    }

    /**
     * @param  array<string, mixed>|null  $normalizedRules
     * @param  array<int, string>  $errors
     * @return array<int, string>
     */
    private function buildRequirementSummary(?array $normalizedRules, array $errors): array
    {
        if ($errors !== []) {
            return ['Stored grant requirements are invalid and need to be re-saved.'];
        }

        return $this->grantRequirementService->summarize($normalizedRules);
    }

    private function normalizeComparableValue(mixed $value): string
    {
        if (is_array($value)) {
            return json_encode($value, JSON_THROW_ON_ERROR);
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if ($value === null) {
            return '';
        }

        return (string) $value;
    }

    /**
     * @throws AuthorizationException
     */
    public function approveApplication(
        ApproveGrantApplicationRequest $request,
        GrantApplication $application,
    ): RedirectResponse {
        if ($application->status !== 'pending') {
            return redirect()->back()->with([
                'alert-message' => 'Grant application is not pending.',
                'alert-type' => 'error',
            ]);
        }

        try {
            GrantService::approveGrant($application, $request->decision());
        } catch (ValidationException $exception) {
            $details = collect($exception->errors())->flatten()->implode(' ');

            return redirect()->back()->with([
                'alert-message' => $details ?: 'Unable to approve this grant application.',
                'alert-type' => 'error',
            ]);
        }

        return redirect()->back()
            ->with('alert-message', 'Grant approved and funds distributed.')
            ->with('alert-type', 'success');
    }

    /**
     * @throws AuthorizationException
     */
    public function denyApplication(
        DenyGrantApplicationRequest $request,
        GrantApplication $application,
    ): RedirectResponse {
        if ($application->status !== 'pending') {
            return redirect()->back()->with([
                'alert-message' => 'Grant application is not pending.',
                'alert-type' => 'error',
            ]);
        }

        try {
            GrantService::denyGrant($application, $request->decision());
        } catch (ValidationException $exception) {
            $details = collect($exception->errors())->flatten()->implode(' ');

            return redirect()->back()->with([
                'alert-message' => $details ?: 'Unable to deny this grant application.',
                'alert-type' => 'error',
            ]);
        }

        return redirect()->back()
            ->with('alert-message', 'Grant application denied.')
            ->with('alert-type', 'success');
    }
}
