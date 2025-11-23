<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditPriority;
use App\Enums\AuditTargetType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AuditRuleRequest;
use App\Models\AuditResult;
use App\Models\AuditRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class AuditRuleController extends Controller
{
    public function index(): View
    {
        $priorityOrder = "FIELD(priority, 'high', 'medium', 'low', 'info')";

        $rules = AuditRule::query()
            ->withCount('results')
            ->orderByRaw($priorityOrder)
            ->orderBy('name')
            ->get();

        return view('admin.audits.rules.index', [
            'rules' => $rules,
        ]);
    }

    public function create(): View
    {
        return view('admin.audits.rules.create', [
            'rule' => new AuditRule,
            'priorities' => AuditPriority::cases(),
            'targetTypes' => AuditTargetType::cases(),
        ]);
    }

    public function store(AuditRuleRequest $request): RedirectResponse
    {
        $data = $request->validated();

        AuditRule::query()->create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'target_type' => $data['target_type'],
            'priority' => $data['priority'],
            'expression' => $data['expression'],
            'enabled' => $request->boolean('enabled', true),
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);

        return redirect()->route('admin.audits.rules.index')->with([
            'alert-message' => 'Audit rule created successfully.',
            'alert-type' => 'success',
        ]);
    }

    public function edit(AuditRule $auditRule): View
    {
        return view('admin.audits.rules.edit', [
            'rule' => $auditRule,
            'priorities' => AuditPriority::cases(),
            'targetTypes' => AuditTargetType::cases(),
        ]);
    }

    public function update(AuditRuleRequest $request, AuditRule $auditRule): RedirectResponse
    {
        $data = $request->validated();

        $originalTarget = $auditRule->target_type;
        $originalExpression = $auditRule->expression;

        $auditRule->fill([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'target_type' => $data['target_type'],
            'priority' => $data['priority'],
            'expression' => $data['expression'],
            'enabled' => $request->boolean('enabled', false),
            'updated_by' => auth()->id(),
        ])->save();

        if ($originalTarget !== $auditRule->target_type || $originalExpression !== $auditRule->expression) {
            AuditResult::query()
                ->where('audit_rule_id', $auditRule->id)
                ->delete();
        }

        return redirect()->route('admin.audits.rules.index')->with([
            'alert-message' => 'Audit rule updated successfully.',
            'alert-type' => 'success',
        ]);
    }

    public function destroy(AuditRule $auditRule): RedirectResponse
    {
        $auditRule->update([
            'enabled' => false,
            'updated_by' => auth()->id(),
        ]);

        AuditResult::query()
            ->where('audit_rule_id', $auditRule->id)
            ->delete();

        return redirect()->route('admin.audits.rules.index')->with([
            'alert-message' => 'Audit rule disabled and current violations cleared.',
            'alert-type' => 'success',
        ]);
    }
}
