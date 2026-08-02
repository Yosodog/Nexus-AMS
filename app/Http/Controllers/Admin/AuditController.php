<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAuditRemediationRequest;
use App\Jobs\RunAuditsJob;
use App\Models\AuditResult;
use App\Models\AuditRule;
use App\Services\Audit\AuditRemediationService;
use App\Services\Audit\AuditRuleDefinitionService;
use App\Services\Audit\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AuditController extends Controller
{
    public function __construct(
        private readonly AuditService $auditService,
        private readonly AuditRemediationService $remediationService,
        private readonly AuditRuleDefinitionService $definitions,
    ) {}

    public function index(): View
    {
        $this->authorize('view-audits');

        $priorityOrder = "CASE priority WHEN 'high' THEN 0 WHEN 'medium' THEN 1 WHEN 'low' THEN 2 WHEN 'info' THEN 3 ELSE 4 END";

        $rules = AuditRule::query()
            ->withCount('results')
            ->orderByRaw($priorityOrder)
            ->orderBy('name')
            ->get();

        $rules->each(function (AuditRule $rule): void {
            $rule->setAttribute('plain_language_summary', is_array($rule->definition)
                ? $this->definitions->summarize($rule->definition, $rule->target_type)
                : 'Needs rebuild in the guided editor.');
        });

        $violationsByPriority = AuditResult::query()
            ->join('audit_rules', 'audit_results.audit_rule_id', '=', 'audit_rules.id')
            ->select('audit_rules.priority', DB::raw('count(*) as aggregate'))
            ->groupBy('audit_rules.priority')
            ->pluck('aggregate', 'audit_rules.priority')
            ->toArray();

        $violationsByTarget = AuditResult::query()
            ->select('target_type', DB::raw('count(*) as aggregate'))
            ->groupBy('target_type')
            ->pluck('aggregate', 'target_type')
            ->toArray();

        $summary = [
            'total_rules' => $rules->count(),
            'enabled_rules' => $rules->where('enabled', true)->count(),
            'violations_total' => array_sum($violationsByPriority),
            'violations_by_priority' => $violationsByPriority,
            'violations_by_target' => $violationsByTarget,
            'overdue_findings' => AuditResult::query()->whereNotNull('due_at')->where('due_at', '<', now())->count(),
            'unhealthy_rules' => $rules->filter(fn (AuditRule $rule): bool => in_array(
                $rule->last_evaluation_status?->value,
                ['warning', 'failed', 'migration_failed'],
                true,
            ))->count(),
        ];

        $attentionFindings = AuditResult::query()
            ->with([
                'rule:id,name,priority,target_type',
                'nation:id,nation_name,leader_name',
                'city:id,nation_id,name',
            ])
            ->where(function ($query): void {
                $query->whereHas('rule', fn ($query) => $query->where('priority', 'high'))
                    ->orWhere(function ($query): void {
                        $query->whereNotNull('due_at')->where('due_at', '<', now());
                    });
            })
            ->orderByRaw('CASE WHEN due_at IS NOT NULL AND due_at < ? THEN 0 ELSE 1 END', [now()])
            ->orderBy('due_at')
            ->limit(8)
            ->get();

        return view('admin.audits.index', [
            'rules' => $rules,
            'summary' => $summary,
            'attentionFindings' => $attentionFindings,
        ]);
    }

    public function violations(Request $request, AuditRule $auditRule): View
    {
        $this->authorize('view-audits');

        $query = $auditRule->results()
            ->with([
                'nation:id,leader_name,nation_name,score,num_cities,color',
                'city:id,nation_id,name,infrastructure,land,powered',
            ]);

        if ($request->query('acknowledgement') === 'acknowledged') {
            $query->whereNotNull('acknowledged_at');
        } elseif ($request->query('acknowledgement') === 'unacknowledged') {
            $query->whereNull('acknowledged_at');
        }

        if ($request->query('waiver') === 'active') {
            $query->where('waived_until', '>', now());
        } elseif ($request->query('waiver') === 'none') {
            $query->where(function ($query): void {
                $query->whereNull('waived_until')->orWhere('waived_until', '<=', now());
            });
        }

        match ($request->query('due')) {
            'overdue' => $query->whereNotNull('due_at')->where('due_at', '<', now()),
            'upcoming' => $query->where('due_at', '>=', now()),
            'none' => $query->whereNull('due_at'),
            default => null,
        };

        if ($target = trim((string) $request->query('target'))) {
            $query->where(function ($query) use ($target): void {
                $query->whereHas('nation', function ($query) use ($target): void {
                    $query->where('nation_name', 'like', "%{$target}%")
                        ->orWhere('leader_name', 'like', "%{$target}%");
                })->orWhereHas('city', fn ($query) => $query->where('name', 'like', "%{$target}%"));
            });
        }

        $violations = $query->orderByDesc('last_evaluated_at')->get();

        return view('admin.audits.rule-violations', [
            'rule' => $auditRule,
            'violations' => $violations,
            'plainLanguageSummary' => is_array($auditRule->definition)
                ? $this->definitions->summarize($auditRule->definition, $auditRule->target_type)
                : 'This rule needs to be rebuilt.',
        ]);
    }

    public function run(): RedirectResponse
    {
        $this->authorize('manage-audits');

        RunAuditsJob::dispatch();

        return redirect()->route('admin.audits.index')->with([
            'alert-message' => 'Audit run queued. Violations will refresh after processing.',
            'alert-type' => 'success',
        ]);
    }

    public function updateRemediation(UpdateAuditRemediationRequest $request, AuditResult $auditResult): RedirectResponse
    {
        $validated = $request->validated();

        $this->remediationService->updateByAdmin(
            $request->user(),
            $auditResult,
            isset($validated['due_at']) ? Carbon::parse($validated['due_at']) : null,
            isset($validated['waived_until']) ? Carbon::parse($validated['waived_until']) : null,
            $validated['remediation_note'] ?? null,
            (bool) ($validated['clear_waiver'] ?? false),
        );

        return back()->with([
            'alert-message' => 'Audit remediation updated.',
            'alert-type' => 'success',
        ]);
    }

    public function notify(): RedirectResponse
    {
        $this->authorize('manage-audits');

        return redirect()->route('admin.audits.index')->with([
            'alert-message' => 'Audit reminders are delivered automatically in the daily digest.',
            'alert-type' => 'info',
        ]);
    }
}
