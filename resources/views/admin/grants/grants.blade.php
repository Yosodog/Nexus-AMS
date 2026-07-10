@php
    use App\Services\PWHelperService;
    use Illuminate\Support\Js;
    use Illuminate\Support\Str;

    $oldGrantPayload = null;

    if (old('name') !== null || old('description') !== null || old('validation_rules_json') !== null) {
        $oldGrantPayload = [
            'id' => old('id'),
            'name' => old('name', ''),
            'description' => old('description', ''),
            'money' => old('money', 0),
            'is_enabled' => filter_var(old('is_enabled', '1'), FILTER_VALIDATE_BOOLEAN),
            'is_one_time' => filter_var(old('is_one_time', '0'), FILTER_VALIDATE_BOOLEAN),
            'validation_rules' => old('validation_rules') ?: (old('validation_rules_json') ? json_decode((string) old('validation_rules_json'), true) : null),
        ];

        foreach (PWHelperService::resources(false) as $resource) {
            $oldGrantPayload[$resource] = old($resource, 0);
        }
    }

    $canManageGrants = auth()->user()?->can('manage-grants') ?? false;
    $canBypassSelfRestrictions = auth()->user()?->can('bypass-self-restrictions') ?? false;
@endphp
@extends('layouts.admin')

@section('title', 'Grant Programs')

@section('content')
    <header class="nexus-page-header">
        <div class="nexus-page-header__copy">
            <h1 class="nexus-page-title">Grant programs</h1>
            <p class="nexus-page-summary">Review requests against their exact payout, then maintain reusable grant definitions and eligibility rules.</p>
        </div>
        <div class="nexus-page-header__actions">
            <span class="nexus-status {{ $pendingCount > 0 ? 'nexus-status--warning' : 'nexus-status--success' }}">
                {{ number_format($pendingCount) }} pending
            </span>
            @can('manage-grants')
                <button
                    type="button"
                    onclick="clearGrantForm(); document.getElementById('grantModal').showModal()"
                    class="btn btn-primary btn-sm"
                >
                    <x-icon name="o-plus" class="size-4" aria-hidden="true" />
                    Create grant program
                </button>
            @endcan
        </div>
    </header>

    @unless($grantApprovalsEnabled)
        <div class="alert alert-warning" role="status">
            <x-icon name="o-pause-circle" class="size-5" aria-hidden="true" />
            <div>
                <p class="font-semibold">Grant approvals are paused</p>
                <p class="text-sm">Pending requests are preserved. Staff can still deny a request, but deposits cannot be approved until the global control is enabled.</p>
            </div>
        </div>
    @endunless

    <section class="nexus-panel nexus-panel--raised" aria-labelledby="pending-grants-title">
        <div class="nexus-panel__header">
            <div>
                <h2 id="pending-grants-title" class="nexus-section-title">Pending grant requests</h2>
                <p class="nexus-body-muted mt-1">Approval deposits the displayed payout into the selected account immediately.</p>
            </div>
            @unless($canManageGrants)
                <span class="nexus-status nexus-status--neutral">View only</span>
            @endunless
        </div>

        @forelse ($pendingRequests as $request)
            @php
                $isOwnRequest = ! $canBypassSelfRestrictions
                    && auth()->user()?->nation_id !== null
                    && (int) auth()->user()->nation_id === (int) $request->nation_id;
                $payoutResources = collect(PWHelperService::resources())
                    ->mapWithKeys(fn ($resource) => [$resource => (float) ($request->grant?->$resource ?? 0)])
                    ->filter(fn ($amount) => $amount > 0);
            @endphp
            <article class="grid gap-4 border-b border-base-300 px-5 py-4 last:border-b-0 xl:grid-cols-[minmax(0,1.05fr)_minmax(16rem,0.95fr)_auto] xl:items-center">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h3 class="font-semibold">{{ $request->grant?->name ?? 'Unknown grant' }}</h3>
                        <span class="nexus-status nexus-status--warning">Pending</span>
                    </div>
                    @if ($request->nation)
                        <a href="https://politicsandwar.com/nation/id={{ $request->nation->id }}" target="_blank" rel="noopener" class="mt-1 block w-fit font-medium text-primary hover:underline">
                            {{ $request->nation->leader_name ?? ('Nation #'.$request->nation->id) }}
                        </a>
                        <p class="text-sm text-base-content/60">{{ $request->nation->nation_name ?? 'Unknown nation name' }} · Nation #{{ $request->nation_id }}</p>
                    @else
                        <p class="mt-1 text-sm text-base-content/60">Unknown nation · Nation #{{ $request->nation_id }}</p>
                    @endif
                    <p class="mt-1 text-xs text-base-content/55">
                        Requested <time datetime="{{ $request->created_at->toIso8601String() }}" class="tooltip tooltip-bottom cursor-help" data-tip="{{ $request->created_at->toDayDateTimeString() }}" tabindex="0" aria-label="Requested {{ $request->created_at->diffForHumans() }}, {{ $request->created_at->toDayDateTimeString() }}">{{ $request->created_at->diffForHumans() }}</time>
                        · Account {{ $request->account?->name ?? '#'.$request->account_id }}
                    </p>
                </div>

                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-base-content/55">Payout on approval</p>
                    <div class="mt-2 flex flex-wrap gap-2">
                        @forelse($payoutResources as $resource => $amount)
                            <span class="nexus-status nexus-status--neutral">
                                {{ Str::headline($resource) }} {{ $resource === 'money' ? '$'.number_format($amount, 0) : number_format($amount, 0) }}
                            </span>
                        @empty
                            <span class="text-sm text-base-content/60">No payout configured</span>
                        @endforelse
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2 xl:justify-end">
                    @if($canManageGrants && ! $isOwnRequest)
                        @if($grantApprovalsEnabled)
                            <form action="{{ route('admin.grants.approve', $request) }}" method="POST" data-confirm="Approve this request and deposit the displayed payout into the selected account?" data-confirm-title="Approve grant request?" data-confirm-label="Approve and deposit">
                                @csrf
                                <button type="submit" class="btn btn-success btn-sm">Approve and deposit</button>
                            </form>
                        @else
                            <span class="nexus-status nexus-status--warning">Approval paused</span>
                        @endif
                        <form action="{{ route('admin.grants.deny', $request) }}" method="POST" data-confirm="Deny this grant request? The applicant will be notified and no funds will be deposited." data-confirm-title="Deny grant request?" data-confirm-label="Deny request" data-confirm-tone="error">
                            @csrf
                            <button type="submit" class="btn btn-error btn-outline btn-sm">Deny request</button>
                        </form>
                    @elseif($isOwnRequest)
                        <span class="text-sm">
                            <span class="nexus-status nexus-status--error">Self-decision blocked</span>
                            <span class="mt-1 block text-base-content/60">Another reviewer must decide.</span>
                        </span>
                    @else
                        <span class="nexus-status nexus-status--neutral">Decision unavailable</span>
                    @endif
                </div>
            </article>
        @empty
            <div class="nexus-empty-state">
                <x-icon name="o-check-circle" class="size-8 text-success" aria-hidden="true" />
                <div>
                    <h3 class="font-semibold">Grant queue is clear</h3>
                    <p class="mt-1 text-sm text-base-content/60">There are no pending custom grant requests.</p>
                </div>
            </div>
        @endforelse
    </section>

    <dl class="nexus-metrics">
        <div class="nexus-metric">
            <dt class="nexus-stat-label">Pending</dt>
            <dd class="nexus-stat-value">{{ number_format($pendingCount) }}</dd>
            <p class="nexus-stat-helper">Awaiting a decision</p>
        </div>
        <div class="nexus-metric">
            <dt class="nexus-stat-label">Approved</dt>
            <dd class="nexus-stat-value">{{ number_format($totalApproved) }}</dd>
            <p class="nexus-stat-helper">All-time decisions</p>
        </div>
        <div class="nexus-metric">
            <dt class="nexus-stat-label">Denied</dt>
            <dd class="nexus-stat-value">{{ number_format($totalDenied) }}</dd>
            <p class="nexus-stat-helper">All-time decisions</p>
        </div>
        <div class="nexus-metric">
            <dt class="nexus-stat-label">Funds distributed</dt>
            <dd class="nexus-stat-value">${{ number_format($totalFundsDistributed) }}</dd>
            <p class="nexus-stat-helper">Approved money payouts</p>
        </div>
    </dl>

    @can('manage-grants')
        <details id="manual-grant-disbursement" class="nexus-panel" @if(old('grant_id') || old('nation_id') || old('account_id')) open @endif>
            <summary class="flex cursor-pointer list-none items-center justify-between gap-4 px-5 py-4 marker:hidden">
                <span>
                    <span class="block font-semibold">Manual grant disbursement</span>
                    <span class="mt-0.5 block text-sm text-base-content/60">Immediately sends a grant and bypasses one-time and pending-request checks.</span>
                </span>
                <span class="flex items-center gap-2">
                    <span class="nexus-status {{ $grantApprovalsEnabled ? 'nexus-status--warning' : 'nexus-status--neutral' }}">
                        {{ $grantApprovalsEnabled ? 'Elevated action' : 'Paused' }}
                    </span>
                    <x-icon name="o-chevron-down" class="size-4 text-base-content/50" aria-hidden="true" />
                </span>
            </summary>
            @if($grantApprovalsEnabled)
                <form method="POST" action="{{ route('admin.manual-disbursements.grants') }}" class="border-t border-base-300 p-5" data-confirm="Send this grant immediately? This bypasses the normal application and one-time checks." data-confirm-title="Send manual grant?" data-confirm-label="Send grant" data-confirm-tone="error">
                    @csrf
                    <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', (string) \Illuminate\Support\Str::uuid()) }}">
                    <div class="grid gap-4 md:grid-cols-3">
                        <label class="block">
                            <span class="label font-semibold text-sm">Grant</span>
                            <select name="grant_id" class="select w-full" required>
                                <option value="">Select a grant</option>
                                @foreach($grants as $grant)
                                    <option value="{{ $grant->id }}" @selected(old('grant_id') == $grant->id)>
                                        {{ $grant->name }} ({{ $grant->is_one_time ? 'one-time' : 'repeatable' }})
                                    </option>
                                @endforeach
                            </select>
                        </label>
                        <x-input label="Nation ID" type="number" name="nation_id" required min="1" :value="old('nation_id')" />
                        <x-input label="Account ID" type="number" name="account_id" required min="1" :value="old('account_id')" hint="Must belong to the nation above." />
                    </div>
                    <div class="nexus-form-actions mt-5">
                        <button type="submit" class="btn btn-primary">Send grant immediately</button>
                    </div>
                </form>
            @else
                <div class="border-t border-base-300 p-5 text-sm text-base-content/65">
                    Manual disbursements are unavailable while grant approvals are paused.
                </div>
            @endif
        </details>
    @endcan

    <section class="nexus-panel" aria-labelledby="grant-programs-title">
        <div class="nexus-panel__header">
            <div>
                <h2 id="grant-programs-title" class="nexus-section-title">Grant definitions</h2>
                <p class="nexus-body-muted mt-1">Payouts and eligibility rules used by member applications.</p>
            </div>
            <span class="text-sm tabular-nums text-base-content/60">{{ number_format($grants->count()) }} programs</span>
        </div>
        <div class="overflow-x-auto">
            <table class="nexus-table" data-sortable="false">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Status</th>
                        <th>One-Time</th>
                        <th>Requirements</th>
                        <th>Resources</th>
                        <th class="text-right" data-sortable="false">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($grants as $grant)
                        <tr>
                            <td>
                                <div class="font-semibold">{{ $grant->name }}</div>
                                <div class="text-sm text-base-content/50">{{ Str::limit($grant->description, 72) }}</div>
                            </td>
                            <td>
                                <span class="nexus-status {{ $grant->is_enabled ? 'nexus-status--success' : 'nexus-status--neutral' }}">
                                    {{ $grant->is_enabled ? 'Enabled' : 'Disabled' }}
                                </span>
                            </td>
                            <td>{{ $grant->is_one_time ? 'Yes' : 'No' }}</td>
                            <td>
                                @if (!empty($grant->requirement_summary))
                                    <div class="flex flex-wrap gap-1">
                                        @foreach (array_slice($grant->requirement_summary, 0, 3) as $summary)
                                            <x-badge :value="$summary" class="badge-ghost badge-sm" />
                                        @endforeach
                                        @if (count($grant->requirement_summary) > 3)
                                            <x-badge  value="+{{ count($grant->requirement_summary) - 3 }} more" class="badge-neutral badge-sm" />
                                        @endif
                                    </div>
                                @else
                                    <span class="text-base-content/50 text-sm">No custom requirements</span>
                                @endif
                            </td>
                            <td>
                                <button
                                    type="button"
                                    class="btn btn-xs btn-ghost btn-outline"
                                    aria-controls="grant-resources-{{ $grant->id }}"
                                    aria-expanded="false"
                                    onclick="toggleGrantResources({{ $grant->id }}, this)"
                                >
                                    <x-mary-icon name="o-eye" />
                                    <span>Resources</span>
                                </button>
                            </td>
                            <td class="text-right">
                                @can('manage-grants')
                                    <button
                                        type="button"
                                        class="btn btn-primary btn-sm"
                                        onclick="editGrant({{ Js::from($grant) }}); document.getElementById('grantModal').showModal()"
                                    >
                                        <x-mary-icon name="o-pencil" />
                                        <span>Edit</span>
                                    </button>
                                @endcan
                            </td>
                        </tr>
                        <tr id="grant-resources-{{ $grant->id }}" class="hidden bg-base-200/40">
                            <td colspan="6">
                                <div class="flex flex-wrap gap-2 py-1">
                                    @foreach (PWHelperService::resources() as $resource)
                                        @if ((int) $grant->$resource > 0)
                                            <x-badge class="badge-outline badge-sm">
                                                <strong>{{ ucfirst($resource) }}:</strong>&nbsp;{{ number_format($grant->$resource) }}
                                            </x-badge>
                                        @endif
                                    @endforeach
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-8 text-center text-base-content/60">No grant definitions have been created.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    @can('manage-grants')
    <dialog id="grantModal" class="modal modal-bottom sm:modal-middle" aria-labelledby="grant-modal-title">
        <div class="modal-box w-11/12 max-w-5xl max-h-[90vh] overflow-y-auto">
            <div class="flex items-start justify-between mb-4">
                <div>
                    <h3 id="grant-modal-title" class="font-bold text-lg">Manage grant program</h3>
                    <p class="text-base-content/50 text-sm">Create flexible grant requirements with nested logic, live summaries, and safe server-side enforcement.</p>
                </div>
                <button type="button" onclick="document.getElementById('grantModal').close()" class="btn btn-sm btn-ghost">Close</button>
            </div>

            <form id="grantForm" method="POST">
                @csrf
                <input type="hidden" name="id" id="grant_id">
                <input type="hidden" name="validation_rules_json" id="validation_rules_json">

                <div class="grid grid-cols-1 lg:grid-cols-5 gap-4">
                    {{-- Left: Grant Basics --}}
                    <div class="lg:col-span-2 bg-base-200 rounded-box p-4 space-y-4">
                        <div class="font-semibold">Grant basics</div>
                        <x-input label="Grant Name" name="name" id="grant_name" required />
                        <x-textarea label="Description" name="description" id="grant_description" rows="3" />
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="label text-sm font-semibold">Status</label>
                                <select class="select w-full" name="is_enabled" id="is_enabled">
                                    <option value="1">Enabled</option>
                                    <option value="0">Disabled</option>
                                </select>
                            </div>
                            <div>
                                <label class="label text-sm font-semibold">Grant Type</label>
                                <label class="flex items-center gap-2 cursor-pointer mt-2">
                                    <input type="checkbox" class="toggle toggle-primary" id="is_one_time" name="is_one_time">
                                    <span class="text-sm">One-time</span>
                                </label>
                            </div>
                        </div>

                        <div class="divider"></div>

                        <div class="font-semibold">Disbursement</div>
                        <x-input label="Money" type="number" name="money" id="grant_money" value="0" min="0" />
                        <div class="grid grid-cols-2 gap-3">
                            @foreach (PWHelperService::resources(false) as $resource)
                                <x-input :label="ucfirst($resource)" type="number" name="{{ $resource }}"
                                         id="grant_{{ $resource }}" value="0" min="0" />
                            @endforeach
                        </div>
                    </div>

                    {{-- Right: Eligibility Builder --}}
                    <div class="lg:col-span-3 space-y-3">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="font-semibold">Eligibility Builder</div>
                                <div class="text-sm text-base-content/50">Combine fields, comparisons, project checks, and nested logic.</div>
                            </div>
                            <div class="flex gap-2 shrink-0">
                                <button type="button" data-grant-action="add-condition" class="btn btn-outline btn-primary btn-sm">Add Condition</button>
                                <button type="button" data-grant-action="add-group" class="btn btn-outline btn-sm">Add Group</button>
                            </div>
                        </div>

                        @if ($oldGrantPayload && $errors->any())
                            <x-alert class="alert-error">
                                <div class="font-semibold mb-1">Please fix the highlighted grant form issues.</div>
                                <ul class="list-disc list-inside text-sm">
                                    @foreach ($errors->all() as $error)
                                        <li>{{ $error }}</li>
                                    @endforeach
                                </ul>
                            </x-alert>
                        @endif

                        <div class="bg-base-200 border border-base-300 rounded-box p-3">
                            <div class="text-sm font-semibold text-base-content/70 mb-1">Live summary</div>
                            <div class="font-semibold" id="requirementRuleCount">No custom requirements configured</div>
                            <div class="text-sm text-base-content/50 mt-1" id="requirementSummaryHint">Applications will only enforce the standard alliance and pending checks until you add rules.</div>
                        </div>

                        <div class="border border-base-300 bg-base-200 rounded-box p-3">
                            <div class="flex justify-between items-center mb-2">
                                <label class="font-semibold text-sm">Top-level logic</label>
                                <div id="rootRuleBadge" class="badge badge-neutral badge-sm">0 rules</div>
                            </div>
                            <select class="select select-sm w-full" id="root_group_mode"></select>
                        </div>

                        <div id="grantRequirementBuilder" class="space-y-3"></div>

                        <div class="bg-base-200 rounded-box p-3">
                            <div class="text-sm font-semibold text-base-content/70 mb-2">Builder tips</div>
                            <ul class="text-sm text-base-content/60 list-disc list-inside space-y-1">
                                <li>Use <strong>Any condition may match</strong> when several different paths should qualify.</li>
                                <li>Use nested groups to combine project checks with city, score, or MMR ranges.</li>
                                <li>Add a custom message on any condition to show a specific denial reason to the applicant.</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <div class="modal-action">
                    <x-button label="Save Grant" type="submit" icon="o-check" class="btn-primary" />
                    <x-button label="Cancel" type="button" onclick="document.getElementById('grantModal').close()" class="btn-ghost" />
                </div>
            </form>
        </div>
        <form method="dialog" class="modal-backdrop"><button>close</button></form>
    </dialog>
    @endcan
@endsection

@push('scripts')
    <script>
        const grantRequirementBuilderConfig = @json($grantRequirementBuilderConfig);
        const grantRequirementFields = new Map(grantRequirementBuilderConfig.fields.map(field => [field.key, field]));
        const grantRequirementOperators = new Map(grantRequirementBuilderConfig.operators.map(operator => [operator.value, operator]));
        const cloneData = data => JSON.parse(JSON.stringify(data));
        const defaultGrantRequirementTree = cloneData(grantRequirementBuilderConfig.default_tree);
        let grantRequirementTree = cloneData(defaultGrantRequirementTree);

        function createEmptyCondition(fieldKey = null) {
            const initialField = fieldKey || grantRequirementBuilderConfig.fields[0]?.key || 'num_cities';
            const field = grantRequirementFields.get(initialField);
            const operator = field?.operators?.[0] || 'gte';
            return { field: initialField, operator, value: defaultValueFor(field, operator), message: '' };
        }

        function createEmptyGroup(group = 'all') {
            return {group, rules: []};
        }

        function defaultValueFor(field, operator) {
            if (!field) return '';
            if (field.type === 'number') return ['between', 'not_between'].includes(operator) ? {min: '', max: ''} : '';
            if (field.type === 'enum') {
                if (['in', 'not_in'].includes(operator)) return [];
                return field.options?.[0]?.value || '';
            }
            return [];
        }

        function ensureValidTree(tree) {
            if (!tree || typeof tree !== 'object' || Array.isArray(tree)) return cloneData(defaultGrantRequirementTree);
            const group = ['all', 'any', 'not'].includes(tree.group) ? tree.group : 'all';
            const rules = Array.isArray(tree.rules) ? tree.rules.map(ensureValidNode).filter(Boolean) : [];
            return {group, rules};
        }

        function ensureValidNode(node) {
            if (!node || typeof node !== 'object' || Array.isArray(node)) return null;
            if (Object.prototype.hasOwnProperty.call(node, 'group')) return ensureValidTree(node);
            const field = grantRequirementFields.get(node.field) || grantRequirementFields.get(grantRequirementBuilderConfig.fields[0]?.key);
            const operator = field.operators.includes(node.operator) ? node.operator : field.operators[0];
            return { field: field.key, operator, value: normalizeConditionValue(field, operator, node.value), message: typeof node.message === 'string' ? node.message : '' };
        }

        function normalizeConditionValue(field, operator, value) {
            if (field.type === 'number') {
                if (['between', 'not_between'].includes(operator)) return { min: value && typeof value === 'object' ? value.min ?? '' : '', max: value && typeof value === 'object' ? value.max ?? '' : '' };
                return value ?? '';
            }
            if (field.type === 'enum') {
                if (['in', 'not_in'].includes(operator)) return Array.isArray(value) ? value : [];
                return value ?? field.options?.[0]?.value ?? '';
            }
            return Array.isArray(value) ? value : [];
        }

        function renderGrantRequirementBuilder() {
            const builder = document.getElementById('grantRequirementBuilder');
            builder.innerHTML = '';
            grantRequirementTree.rules.forEach((rule, index) => builder.appendChild(renderNode(rule, [index], true)));
            syncGrantRequirementHiddenInput();
            updateGrantRequirementSummary();
        }

        function renderRootGroupModeSelect() {
            const select = document.getElementById('root_group_mode');
            if (!select) return;
            select.innerHTML = '';
            grantRequirementBuilderConfig.groups.forEach(groupOption => {
                const option = document.createElement('option');
                option.value = groupOption.value;
                option.textContent = groupOption.label;
                option.selected = groupOption.value === grantRequirementTree.group;
                select.appendChild(option);
            });
        }

        function renderNode(node, path, isRootLevel = false) {
            return node.group ? renderGroupNode(node, path, isRootLevel) : renderConditionNode(node, path, isRootLevel);
        }

        function renderGroupNode(node, path, isRootLevel) {
            const card = document.createElement('div');
            card.className = 'border border-base-300 rounded-box p-3 bg-base-200';
            const header = document.createElement('div');
            header.className = 'flex flex-wrap justify-between items-center gap-2 mb-3';
            card.appendChild(header);
            const labelWrap = document.createElement('div');
            header.appendChild(labelWrap);
            const label = document.createElement('div');
            label.className = 'font-semibold text-sm';
            label.textContent = isRootLevel ? 'Nested Group' : 'Requirement Group';
            labelWrap.appendChild(label);
            const small = document.createElement('div');
            small.className = 'text-xs text-base-content/50';
            small.textContent = 'Use nested logic when one path or another should qualify.';
            labelWrap.appendChild(small);
            const actions = document.createElement('div');
            actions.className = 'flex flex-wrap gap-2';
            header.appendChild(actions);
            actions.appendChild(createButton('Condition', 'btn btn-outline btn-primary btn-xs', () => { node.rules.push(createEmptyCondition()); renderGrantRequirementBuilder(); }));
            actions.appendChild(createButton('Group', 'btn btn-outline btn-xs', () => { node.rules.push(createEmptyGroup('all')); renderGrantRequirementBuilder(); }));
            actions.appendChild(createMoveButton('Move up', path, -1));
            actions.appendChild(createMoveButton('Move down', path, 1));
            if (!isRootLevel) actions.appendChild(createButton('Remove', 'btn btn-error btn-outline btn-xs', () => { removeNodeAtPath(path); renderGrantRequirementBuilder(); }));
            const select = document.createElement('select');
            select.className = 'select select-sm w-full mb-3';
            grantRequirementBuilderConfig.groups.forEach(groupOption => {
                const option = document.createElement('option');
                option.value = groupOption.value;
                option.textContent = groupOption.label;
                option.selected = groupOption.value === node.group;
                select.appendChild(option);
            });
            select.addEventListener('change', event => { node.group = event.target.value; syncGrantRequirementHiddenInput(); updateGrantRequirementSummary(); });
            card.appendChild(select);
            if (node.rules.length === 0) {
                const empty = document.createElement('div');
                empty.className = 'border border-base-300 rounded-box p-3 text-base-content/50 text-sm';
                empty.textContent = 'This group is empty. Add a condition or subgroup to make it active.';
                card.appendChild(empty);
            } else {
                const children = document.createElement('div');
                children.className = 'space-y-3';
                node.rules.forEach((child, childIndex) => children.appendChild(renderNode(child, [...path, childIndex])));
                card.appendChild(children);
            }
            return card;
        }

        function renderConditionNode(node, path) {
            const field = grantRequirementFields.get(node.field) || grantRequirementFields.get(grantRequirementBuilderConfig.fields[0].key);
            const allowedOperators = field.operators.map(operatorKey => grantRequirementOperators.get(operatorKey));
            if (!field.operators.includes(node.operator)) { node.operator = field.operators[0]; node.value = defaultValueFor(field, node.operator); }
            const card = document.createElement('div');
            card.className = 'border border-base-300 rounded-box p-3 bg-base-100';
            const grid = document.createElement('div');
            grid.className = 'grid grid-cols-1 md:grid-cols-3 gap-3';
            card.appendChild(grid);
            const fieldCol = document.createElement('div');
            grid.appendChild(fieldCol);
            fieldCol.appendChild(createFieldSelect(field, node));
            const operatorCol = document.createElement('div');
            grid.appendChild(operatorCol);
            operatorCol.appendChild(createOperatorSelect(node, allowedOperators));
            const valueCol = document.createElement('div');
            grid.appendChild(valueCol);
            valueCol.appendChild(createValueEditor(field, node));
            const footer = document.createElement('div');
            footer.className = 'flex gap-3 mt-3 items-end';
            card.appendChild(footer);
            const messageWrap = document.createElement('div');
            messageWrap.className = 'grow';
            const messageLabel = document.createElement('label');
            messageLabel.className = 'label text-xs text-base-content/60';
            messageLabel.textContent = 'Custom failure message';
            messageWrap.appendChild(messageLabel);
            const messageInput = document.createElement('input');
            messageInput.type = 'text';
            messageInput.className = 'input input-sm w-full';
            messageInput.placeholder = 'Optional. Shown to the applicant if this condition fails.';
            messageInput.value = node.message || '';
            messageInput.addEventListener('input', event => { node.message = event.target.value; syncGrantRequirementHiddenInput(); });
            messageWrap.appendChild(messageInput);
            footer.appendChild(messageWrap);
            const actionsDiv = document.createElement('div');
            actionsDiv.className = 'flex gap-2 shrink-0';
            actionsDiv.appendChild(createMoveButton('Move up', path, -1));
            actionsDiv.appendChild(createMoveButton('Move down', path, 1));
            actionsDiv.appendChild(createButton('Remove', 'btn btn-error btn-outline btn-xs', () => { removeNodeAtPath(path); renderGrantRequirementBuilder(); }));
            footer.appendChild(actionsDiv);
            return card;
        }

        function createFieldSelect(field, node) {
            const wrapper = document.createElement('div');
            const label = document.createElement('label');
            label.className = 'label text-xs font-semibold';
            label.textContent = 'Field';
            wrapper.appendChild(label);
            const select = document.createElement('select');
            select.className = 'select select-sm w-full';
            wrapper.appendChild(select);
            const grouped = {};
            grantRequirementBuilderConfig.fields.forEach(item => { grouped[item.category] = grouped[item.category] || []; grouped[item.category].push(item); });
            Object.entries(grouped).forEach(([category, items]) => {
                const group = document.createElement('optgroup');
                group.label = category;
                items.forEach(item => { const option = document.createElement('option'); option.value = item.key; option.textContent = item.label; option.selected = item.key === field.key; group.appendChild(option); });
                select.appendChild(group);
            });
            select.addEventListener('change', event => { const nextField = grantRequirementFields.get(event.target.value); node.field = nextField.key; node.operator = nextField.operators[0]; node.value = defaultValueFor(nextField, node.operator); renderGrantRequirementBuilder(); });
            return wrapper;
        }

        function createOperatorSelect(node, allowedOperators) {
            const wrapper = document.createElement('div');
            const label = document.createElement('label');
            label.className = 'label text-xs font-semibold';
            label.textContent = 'Operator';
            wrapper.appendChild(label);
            const select = document.createElement('select');
            select.className = 'select select-sm w-full';
            allowedOperators.forEach(operator => { const option = document.createElement('option'); option.value = operator.value; option.textContent = operator.label; option.selected = operator.value === node.operator; select.appendChild(option); });
            select.addEventListener('change', event => { const field = grantRequirementFields.get(node.field); node.operator = event.target.value; node.value = defaultValueFor(field, node.operator); renderGrantRequirementBuilder(); });
            wrapper.appendChild(select);
            return wrapper;
        }

        function createValueEditor(field, node) {
            const wrapper = document.createElement('div');
            const label = document.createElement('label');
            label.className = 'label text-xs font-semibold';
            label.textContent = 'Value';
            wrapper.appendChild(label);
            if (field.type === 'number' && ['between', 'not_between'].includes(node.operator)) {
                const row = document.createElement('div');
                row.className = 'grid grid-cols-2 gap-2';
                wrapper.appendChild(row);
                row.appendChild(createNumberInput('Minimum', node.value?.min ?? '', value => { node.value = {...(node.value || {}), min: value}; syncGrantRequirementHiddenInput(); updateGrantRequirementSummary(); }));
                row.appendChild(createNumberInput('Maximum', node.value?.max ?? '', value => { node.value = {...(node.value || {}), max: value}; syncGrantRequirementHiddenInput(); updateGrantRequirementSummary(); }));
                return wrapper;
            }
            if (field.type === 'number') {
                const input = document.createElement('input');
                input.type = 'number';
                input.className = 'input input-sm w-full';
                input.step = 'any';
                input.value = node.value ?? '';
                input.addEventListener('input', event => { node.value = event.target.value; syncGrantRequirementHiddenInput(); updateGrantRequirementSummary(); });
                wrapper.appendChild(input);
                return wrapper;
            }
            if (field.type === 'enum' && ['eq', 'neq'].includes(node.operator)) {
                const select = document.createElement('select');
                select.className = 'select select-sm w-full';
                field.options.forEach(optionData => { const option = document.createElement('option'); option.value = optionData.value; option.textContent = optionData.label; option.selected = optionData.value === node.value; select.appendChild(option); });
                select.addEventListener('change', event => { node.value = event.target.value; syncGrantRequirementHiddenInput(); updateGrantRequirementSummary(); });
                wrapper.appendChild(select);
                return wrapper;
            }
            const select = document.createElement('select');
            select.className = 'select select-sm w-full';
            select.multiple = true;
            select.size = Math.min(6, Math.max(4, field.options.length));
            const selectedValues = Array.isArray(node.value) ? node.value : [];
            field.options.forEach(optionData => { const option = document.createElement('option'); option.value = optionData.value; option.textContent = optionData.label; option.selected = selectedValues.includes(optionData.value); select.appendChild(option); });
            select.addEventListener('change', event => { node.value = Array.from(event.target.selectedOptions).map(option => option.value); syncGrantRequirementHiddenInput(); updateGrantRequirementSummary(); });
            wrapper.appendChild(select);
            const help = document.createElement('div');
            help.className = 'text-xs text-base-content/50 mt-1';
            help.textContent = 'Hold Command or Ctrl to select multiple values.';
            wrapper.appendChild(help);
            return wrapper;
        }

        function createNumberInput(labelText, value, onInput) {
            const col = document.createElement('div');
            const label = document.createElement('label');
            label.className = 'label text-xs text-base-content/60';
            label.textContent = labelText;
            col.appendChild(label);
            const input = document.createElement('input');
            input.type = 'number';
            input.className = 'input input-sm w-full';
            input.step = 'any';
            input.value = value;
            input.addEventListener('input', event => onInput(event.target.value));
            col.appendChild(input);
            return col;
        }

        function createButton(label, classes, handler) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = classes;
            button.textContent = label;
            button.addEventListener('click', handler);
            return button;
        }

        function createMoveButton(label, path, offset) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-outline btn-xs';
            button.textContent = label;
            button.addEventListener('click', () => { moveNodeAtPath(path, offset); renderGrantRequirementBuilder(); });
            return button;
        }

        function getParentArray(path) {
            let target = grantRequirementTree.rules;
            for (let index = 0; index < path.length - 1; index++) target = target[path[index]].rules;
            return target;
        }

        function removeNodeAtPath(path) { getParentArray(path).splice(path[path.length - 1], 1); }

        function moveNodeAtPath(path, offset) {
            const parent = getParentArray(path);
            const currentIndex = path[path.length - 1];
            const nextIndex = currentIndex + offset;
            if (nextIndex < 0 || nextIndex >= parent.length) return;
            const [item] = parent.splice(currentIndex, 1);
            parent.splice(nextIndex, 0, item);
        }

        function syncGrantRequirementHiddenInput() {
            const normalized = ensureValidTree(grantRequirementTree);
            document.getElementById('validation_rules_json').value = normalized.rules.length > 0 ? JSON.stringify(normalized) : '';
        }

        function updateGrantRequirementSummary() {
            const summaryTarget = document.getElementById('requirementSummaryHint');
            const countTarget = document.getElementById('requirementRuleCount');
            const badgeTarget = document.getElementById('rootRuleBadge');
            const ruleCount = countNodes(grantRequirementTree.rules);
            badgeTarget.textContent = `${ruleCount} ${ruleCount === 1 ? 'rule' : 'rules'}`;
            if (ruleCount === 0) {
                countTarget.textContent = 'No custom requirements configured';
                summaryTarget.textContent = 'Applications will only enforce the standard alliance and pending checks until you add rules.';
                return;
            }
            countTarget.textContent = `${ruleCount} custom ${ruleCount === 1 ? 'rule' : 'rules'} will be enforced`;
            summaryTarget.textContent = summarizeTree(grantRequirementTree).slice(0, 3).join(' • ');
        }

        function countNodes(nodes) { return nodes.reduce((total, node) => node.group ? total + 1 + countNodes(node.rules || []) : total + 1, 0); }

        function summarizeTree(tree) { return (tree.rules || []).map(node => summarizeNode(node)); }

        function summarizeNode(node) {
            if (node.group) {
                const labelMap = { all: 'All of', any: 'Any of', not: 'None of' };
                return `${labelMap[node.group] || 'Group'}: ${(node.rules || []).map(summarizeNode).join('; ')}`;
            }
            const field = grantRequirementFields.get(node.field);
            const operator = grantRequirementOperators.get(node.operator);
            const label = field?.label || node.field;
            if (!field || !operator) return label;
            if (field.type === 'number') {
                if (['between', 'not_between'].includes(node.operator)) return `${label} ${operator.label.toLowerCase()} ${node.value?.min ?? '?'} and ${node.value?.max ?? '?'}`;
                return `${label} ${operator.label.toLowerCase()} ${node.value ?? '?'}`;
            }
            if (Array.isArray(node.value)) return `${label} ${operator.label.toLowerCase()} ${node.value.join(', ')}`;
            return `${label} ${operator.label.toLowerCase()} ${node.value ?? '?'}`;
        }

        function populateForm(grant) {
            document.getElementById('grant_id').value = grant?.id || '';
            document.querySelector('#grantForm [name="name"]').value = grant?.name || '';
            document.querySelector('#grantForm [name="description"]').value = grant?.description || '';
            document.querySelector('#grantForm [name="is_enabled"]').value = grant?.is_enabled ? '1' : '0';
            document.querySelector('#grantForm [name="is_one_time"]').checked = !!grant?.is_one_time;
            document.querySelector('#grantForm [name="money"]').value = grant?.money || 0;
            @foreach (PWHelperService::resources(false) as $resource)
                document.querySelector('#grantForm [name="{{ $resource }}"]').value = grant?.{{ $resource }} || 0;
            @endforeach
            grantRequirementTree = ensureValidTree(grant?.validation_rules || defaultGrantRequirementTree);
            document.getElementById('root_group_mode').value = grantRequirementTree.group;
            renderGrantRequirementBuilder();
        }

        function editGrant(grant) {
            document.getElementById('grantForm').action = `/admin/grants/${grant.id}/update`;
            populateForm(grant);
        }

        function toggleGrantResources(grantId, trigger) {
            const row = document.getElementById(`grant-resources-${grantId}`);
            if (!row) {
                return;
            }

            const isHidden = row.classList.toggle('hidden');

            if (trigger) {
                trigger.setAttribute('aria-expanded', isHidden ? 'false' : 'true');
            }
        }

        function clearGrantForm() {
            document.getElementById('grantForm').action = `/admin/grants/create`;
            populateForm({
                is_enabled: true, is_one_time: false, money: 0,
                validation_rules: cloneData(defaultGrantRequirementTree),
                @foreach (PWHelperService::resources(false) as $resource) {{ $resource }}: 0, @endforeach
            });
        }

        document.addEventListener('codex:page-ready', () => {
            const grantForm = document.getElementById('grantForm');
            const addTopLevelConditionButton = document.querySelector('[data-grant-action="add-condition"]');
            const addTopLevelGroupButton = document.querySelector('[data-grant-action="add-group"]');
            const rootGroupMode = document.getElementById('root_group_mode');

            if (!grantForm || !addTopLevelConditionButton || !addTopLevelGroupButton || !rootGroupMode) {
                return;
            }

            renderRootGroupModeSelect();

            if (grantForm.dataset.builderBound === 'true') {
                return;
            }

            grantForm.dataset.builderBound = 'true';

            addTopLevelConditionButton.addEventListener('click', () => {
                grantRequirementTree.rules.push(createEmptyCondition());
                renderGrantRequirementBuilder();
            });

            addTopLevelGroupButton.addEventListener('click', () => {
                grantRequirementTree.rules.push(createEmptyGroup('all'));
                renderGrantRequirementBuilder();
            });

            rootGroupMode.addEventListener('change', event => {
                grantRequirementTree.group = event.target.value;
                syncGrantRequirementHiddenInput();
                updateGrantRequirementSummary();
            });

            @if ($oldGrantPayload)
                document.getElementById('grantForm').action = "{{ $oldGrantPayload['id'] ? url('/admin/grants/'.$oldGrantPayload['id'].'/update') : url('/admin/grants/create') }}";
                populateForm(@json($oldGrantPayload));
                document.getElementById('grantModal').showModal();
            @else
                clearGrantForm();
            @endif
        });
    </script>
@endpush
