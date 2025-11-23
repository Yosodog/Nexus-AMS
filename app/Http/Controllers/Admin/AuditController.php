<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\RunAuditsJob;
use App\Models\AuditResult;
use App\Models\AuditRule;
use App\Notifications\AuditViolationSummaryNotification;
use App\Services\Audit\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\View\View;

class AuditController extends Controller
{
    public function __construct(private readonly AuditService $auditService) {}

    public function index(): View
    {
        $priorityOrder = "FIELD(priority, 'high', 'medium', 'low', 'info')";

        $rules = AuditRule::query()
            ->withCount('results')
            ->orderByRaw($priorityOrder)
            ->orderBy('name')
            ->get();

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
        ];

        return view('admin.audits.index', [
            'rules' => $rules,
            'summary' => $summary,
        ]);
    }

    public function violations(AuditRule $auditRule): View
    {
        $violations = $auditRule->results()
            ->with([
                'nation:id,leader_name,nation_name,score,num_cities,color',
                'city:id,nation_id,name,infrastructure,land,powered',
            ])
            ->orderByDesc('last_evaluated_at')
            ->get();

        return view('admin.audits.rule-violations', [
            'rule' => $auditRule,
            'violations' => $violations,
        ]);
    }

    public function run(): RedirectResponse
    {
        RunAuditsJob::dispatch();

        return redirect()->route('admin.audits.index')->with([
            'alert-message' => 'Audit run queued. Violations will refresh after processing.',
            'alert-type' => 'success',
        ]);
    }

    public function notify(): RedirectResponse
    {
        $violations = AuditResult::query()
            ->with(['rule', 'city:id,name,infrastructure,land,powered', 'nation:id,leader_name,nation_name'])
            ->get();

        if ($violations->isEmpty()) {
            return redirect()->route('admin.audits.index')->with([
                'alert-message' => 'No current violations to notify.',
                'alert-type' => 'info',
            ]);
        }

        $grouped = $violations->groupBy(fn (AuditResult $result) => $result->nation_id);

        $sent = 0;

        foreach ($grouped as $nationId => $results) {
            if (! $nationId) {
                continue;
            }

            $lines = $results->map(function (AuditResult $result): string {
                $priority = $result->rule?->priority->value ?? 'info';
                $name = $result->rule?->name ?? 'Audit rule';

                if ($result->target_type->value === 'city') {
                    $city = $result->city;
                    $cityLabel = $city ? "{$city->name} (Infra {$city->infrastructure}, Land {$city->land})" : 'City target';

                    return "[{$priority}] {$name} — {$cityLabel}";
                }

                return "[{$priority}] {$name} — Nation-wide";
            })->filter()->values()->all();

            if (empty($lines)) {
                continue;
            }

            Notification::route('pnw', 'pnw')->notify(new AuditViolationSummaryNotification((int) $nationId, $lines));
            $sent++;
        }

        return redirect()->route('admin.audits.index')->with([
            'alert-message' => "Sent in-game audit summaries to {$sent} nation(s).",
            'alert-type' => 'success',
        ]);
    }
}
