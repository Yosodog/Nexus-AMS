<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditEvaluationStatus;
use App\Enums\AuditPriority;
use App\Enums\AuditTargetType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AuditRulePreviewRequest;
use App\Http\Requests\Admin\AuditRuleRequest;
use App\Models\AuditRule;
use App\Services\Audit\AuditFieldRegistry;
use App\Services\Audit\AuditPreviewService;
use App\Services\Audit\AuditRuleDefinitionService;
use App\Services\Audit\AuditRuleLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class AuditRuleController extends Controller
{
    public function __construct(
        private readonly AuditFieldRegistry $fields,
        private readonly AuditRuleDefinitionService $definitions,
        private readonly AuditPreviewService $previewService,
        private readonly AuditRuleLifecycleService $lifecycle,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('view-audits');

        $priorityOrder = "CASE priority WHEN 'high' THEN 0 WHEN 'medium' THEN 1 WHEN 'low' THEN 2 WHEN 'info' THEN 3 ELSE 4 END";
        $query = AuditRule::query()->withCount(['results' => fn ($query) => $query->current()]);

        if ($target = AuditTargetType::tryFrom((string) $request->query('target'))) {
            $query->where('target_type', $target);
        }

        if ($priority = AuditPriority::tryFrom((string) $request->query('priority'))) {
            $query->where('priority', $priority);
        }

        if ($status = AuditEvaluationStatus::tryFrom((string) $request->query('status'))) {
            $query->where('last_evaluation_status', $status);
        }

        if ($request->query->has('enabled') && in_array((string) $request->query('enabled'), ['0', '1'], true)) {
            $query->where('enabled', $request->boolean('enabled'));
        }

        if ($search = trim((string) $request->query('search'))) {
            $query->where(function ($query) use ($search): void {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $rules = $query->orderByRaw($priorityOrder)->orderBy('name')->get();
        $rules->each(function (AuditRule $rule): void {
            $rule->setAttribute('plain_language_summary', is_array($rule->definition)
                ? $this->definitions->summarize($rule->definition, $rule->target_type)
                : 'Needs rebuild in the guided editor.');
        });

        return view('admin.audits.rules.index', [
            'rules' => $rules,
            'targetTypes' => AuditTargetType::cases(),
            'priorities' => AuditPriority::cases(),
            'evaluationStatuses' => AuditEvaluationStatus::cases(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('manage-audits');

        return $this->editorView('admin.audits.rules.create', new AuditRule([
            'target_type' => AuditTargetType::Nation,
            'priority' => AuditPriority::Medium,
            'enabled' => false,
            'revision' => 1,
        ]));
    }

    public function store(AuditRuleRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $targetType = AuditTargetType::from($data['target_type']);
        $definition = $this->definitions->normalize($data['definition'], $targetType);

        $this->lifecycle->create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'remediation_guidance' => $data['remediation_guidance'] ?? null,
            'admin_notes' => $data['admin_notes'] ?? null,
            'target_type' => $targetType,
            'priority' => $data['priority'],
            'definition' => $definition,
            'enabled' => (bool) $data['enabled'],
        ], $request->user());

        return redirect()->route('admin.audits.rules.index')->with([
            'alert-message' => 'Audit rule created successfully.',
            'alert-type' => 'success',
        ]);
    }

    public function edit(AuditRule $auditRule): View
    {
        $this->authorize('manage-audits');

        return $this->editorView('admin.audits.rules.edit', $auditRule);
    }

    public function update(AuditRuleRequest $request, AuditRule $auditRule): RedirectResponse
    {
        $data = $request->validated();
        $targetType = AuditTargetType::from($data['target_type']);

        $this->lifecycle->update($auditRule, [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'remediation_guidance' => $data['remediation_guidance'] ?? null,
            'admin_notes' => $data['admin_notes'] ?? null,
            'target_type' => $targetType,
            'priority' => $data['priority'],
            'definition' => $this->definitions->normalize($data['definition'], $targetType),
            'enabled' => (bool) $data['enabled'],
        ], $request->user());

        return redirect()->route('admin.audits.rules.index')->with([
            'alert-message' => 'Audit rule updated successfully.',
            'alert-type' => 'success',
        ]);
    }

    public function preview(AuditRulePreviewRequest $request): JsonResponse
    {
        try {
            return response()->json($this->previewService->preview(
                $request->user(),
                AuditTargetType::from((string) $request->validated('target_type')),
                $request->validated('definition'),
            ));
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => [
                    'definition' => [$exception->getMessage()],
                ],
            ], 422);
        }
    }

    public function destroy(Request $request, AuditRule $auditRule): RedirectResponse
    {
        $this->authorize('manage-audits');

        $this->lifecycle->disable($auditRule, $request->user());

        return redirect()->route('admin.audits.rules.index')->with([
            'alert-message' => 'Audit rule disabled and current findings closed.',
            'alert-type' => 'success',
        ]);
    }

    private function editorView(string $view, AuditRule $rule): View
    {
        $definition = is_array($rule->definition)
            ? $rule->definition
            : $this->fields->emptyDefinition();

        return view($view, [
            'rule' => $rule,
            'priorities' => AuditPriority::cases(),
            'targetTypes' => AuditTargetType::cases(),
            'builderConfig' => $this->combinedBuilderConfig($rule, $definition),
            'initialDefinition' => $definition,
            'initialSummary' => $this->definitions->summarize($definition, $rule->target_type ?? AuditTargetType::Nation),
            'initialFingerprint' => is_array($rule->definition)
                ? $this->definitions->fingerprint($rule->target_type, $rule->definition)
                : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function combinedBuilderConfig(AuditRule $rule, array $definition): array
    {
        $nation = $this->fields->builderConfig(AuditTargetType::Nation);
        $city = $this->fields->builderConfig(AuditTargetType::City);
        $combined = [];

        foreach ([
            AuditTargetType::Nation->value => $nation['fields'],
            AuditTargetType::City->value => $city['fields'],
        ] as $target => $fields) {
            foreach ($fields as $field) {
                $key = $field['key'];

                if (isset($combined[$key])) {
                    $combined[$key]['targets'][] = $target;
                    $combined[$key]['targets'] = array_values(array_unique($combined[$key]['targets']));

                    continue;
                }

                $combined[$key] = [...$field, 'targets' => [$target]];
            }
        }

        return [
            'fields' => array_values($combined),
            'operators' => $nation['operators'],
            'definition' => $definition,
            'original_definition' => is_array($rule->definition)
                ? $rule->definition
                : $this->fields->emptyDefinition(),
            'default_definition' => $this->fields->emptyDefinition(),
            'original_target' => $rule->target_type?->value ?? AuditTargetType::Nation->value,
            'original_enabled' => (bool) ($rule->enabled ?? false),
        ];
    }
}
