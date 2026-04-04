@php
    use App\Services\PWHelperService;
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
@endphp
@extends('layouts.admin')

@section('content')
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col-sm-6">
                    <h3 class="mb-0">Grant Management</h3>
                </div>
                @can('manage-grants')
                    <div class="col-sm-6 text-sm-end mt-2 mt-sm-0">
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#grantModal"
                                onclick="clearGrantForm()">
                            Create New Grant
                        </button>
                    </div>
                @endcan
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-3">
            <x-admin.info-box icon="bi bi-check-circle" bgColor="text-bg-primary" title="Total Approved"
                              :value="$totalApproved"/>
        </div>
        <div class="col-md-3">
            <x-admin.info-box icon="bi bi-x-circle" bgColor="text-bg-danger" title="Total Denied"
                              :value="$totalDenied"/>
        </div>
        <div class="col-md-3">
            <x-admin.info-box icon="bi bi-hourglass-split" bgColor="text-bg-warning" title="Pending"
                              :value="$pendingCount"/>
        </div>
        <div class="col-md-3">
            <x-admin.info-box icon="bi bi-cash" bgColor="text-bg-success" title="Total Funds Distributed"
                              :value="number_format($totalFundsDistributed)"/>
        </div>
    </div>

    <div class="card mt-4">
        <div class="card-header">Pending Applications</div>
        <div class="card-body">
            @if($pendingRequests->isEmpty())
                <p class="mb-0">No pending applications.</p>
            @else
                <div class="table-responsive">
                    <table class="table table-striped align-middle">
                        <thead>
                        <tr>
                            <th>Grant</th>
                            <th>Nation</th>
                            <th>Account</th>
                            <th>Requested At</th>
                            <th class="text-end">Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($pendingRequests as $request)
                            <tr>
                                <td>{{ $request->grant->name }}</td>
                                <td>
                                    @if ($request->nation)
                                        <a href="https://politicsandwar.com/nation/id={{ $request->nation->id }}"
                                           target="_blank" rel="noopener noreferrer">
                                            {{ $request->nation->leader_name ?? ('Nation #'.$request->nation->id) }}
                                        </a>
                                        <div class="small text-muted">
                                            {{ $request->nation->nation_name ?? 'Unknown Nation' }}
                                        </div>
                                    @else
                                        <span class="text-muted">Unknown Nation</span>
                                    @endif
                                </td>
                                <td>{{ $request->account->name }}</td>
                                <td>{{ $request->created_at->format('M d, Y') }}</td>
                                <td class="text-end">
                                    <form action="{{ route('admin.grants.approve', $request) }}" method="POST"
                                          class="d-inline">
                                        @csrf
                                        <button type="submit" class="btn btn-success btn-sm">Approve</button>
                                    </form>
                                    <form action="{{ route('admin.grants.deny', $request) }}" method="POST"
                                          class="d-inline">
                                        @csrf
                                        <button type="submit" class="btn btn-danger btn-sm">Deny</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    @can('manage-grants')
        <div class="card mt-4">
            <div class="card-header">Manual Grant Disbursement</div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    Sends a grant directly to a nation and bypasses one-time or pending application checks. Use only when an admin must push funds without an application.
                </p>
                <form method="POST" action="{{ route('admin.manual-disbursements.grants') }}">
                    @csrf
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Grant</label>
                            <select name="grant_id" class="form-select" required>
                                <option value="">Select a grant</option>
                                @foreach($grants as $grant)
                                    <option value="{{ $grant->id }}" @selected(old('grant_id') == $grant->id)>
                                        {{ $grant->name }} ({{ $grant->is_one_time ? 'one-time' : 'repeatable' }})
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Nation ID</label>
                            <input type="number" name="nation_id" class="form-control" required min="1"
                                   value="{{ old('nation_id') }}">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Account ID</label>
                            <input type="number" name="account_id" class="form-control" required min="1"
                                   value="{{ old('account_id') }}">
                            <small class="text-muted">Must belong to the nation above.</small>
                        </div>
                    </div>
                    <div class="d-flex justify-content-end mt-3">
                        <button class="btn btn-primary" type="submit">Send Grant</button>
                    </div>
                </form>
            </div>
        </div>
    @endcan

    <div class="card mt-4">
        <div class="card-header">Custom Grants</div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped align-middle">
                    <thead>
                    <tr>
                        <th>Name</th>
                        <th>Status</th>
                        <th>One-Time</th>
                        <th>Requirements</th>
                        <th>Resources</th>
                        <th class="text-end">Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($grants as $grant)
                        <tr>
                            <td>
                                <div class="fw-semibold">{{ $grant->name }}</div>
                                <div class="small text-muted">{{ Str::limit($grant->description, 72) }}</div>
                            </td>
                            <td>
                                <span class="badge {{ $grant->is_enabled ? 'text-bg-success' : 'text-bg-secondary' }}">
                                    {{ $grant->is_enabled ? 'Enabled' : 'Disabled' }}
                                </span>
                            </td>
                            <td>{{ $grant->is_one_time ? 'Yes' : 'No' }}</td>
                            <td>
                                @if (! empty($grant->requirement_summary))
                                    <div class="d-flex flex-wrap gap-1">
                                        @foreach (array_slice($grant->requirement_summary, 0, 3) as $summary)
                                            <span class="badge text-bg-light border">{{ $summary }}</span>
                                        @endforeach
                                        @if (count($grant->requirement_summary) > 3)
                                            <span class="badge text-bg-dark">+{{ count($grant->requirement_summary) - 3 }} more</span>
                                        @endif
                                    </div>
                                @else
                                    <span class="text-muted small">No custom requirements</span>
                                @endif
                            </td>
                            <td>
                                <button class="btn btn-sm btn-outline-info" type="button"
                                        data-bs-toggle="collapse"
                                        data-bs-target="#resources-{{ $grant->id }}"
                                        aria-expanded="false"
                                        aria-controls="resources-{{ $grant->id }}">
                                    View Resources
                                </button>
                            </td>
                            <td class="text-end">
                                <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#grantModal"
                                        onclick='editGrant(@json($grant))'>
                                    Edit
                                </button>
                            </td>
                        </tr>
                        <tr class="collapse" id="resources-{{ $grant->id }}">
                            <td colspan="6">
                                <div class="d-flex flex-wrap gap-2">
                                    @foreach (PWHelperService::resources() as $resource)
                                        @if ((int) $grant->$resource > 0)
                                            <span class="badge text-bg-light border">
                                                <strong>{{ ucfirst($resource) }}:</strong> {{ number_format($grant->$resource) }}
                                            </span>
                                        @endif
                                    @endforeach
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">No grants have been created yet.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="modal modal-xl fade" id="grantModal" tabindex="-1" aria-labelledby="grantModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-scrollable">
            <form id="grantForm" method="POST">
                @csrf
                <input type="hidden" name="id" id="grant_id">
                <input type="hidden" name="validation_rules_json" id="validation_rules_json">
                <div class="modal-content">
                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title mb-1" id="grantModalLabel">Manage Grant</h5>
                            <p class="text-muted small mb-0">Create flexible grant requirements with nested logic, live summaries, and safe server-side enforcement.</p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-4">
                            <div class="col-lg-5">
                                <div class="card h-100 shadow-sm border-0 bg-body-tertiary">
                                    <div class="card-body">
                                        <h6 class="text-uppercase text-muted small mb-3">Grant Basics</h6>
                                        <div class="mb-3">
                                            <label class="form-label">Grant Name</label>
                                            <input type="text" class="form-control" name="name" id="grant_name" required>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label">Description</label>
                                            <textarea class="form-control" name="description" id="grant_description" rows="4"></textarea>
                                        </div>

                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <label class="form-label">Status</label>
                                                <select class="form-select" name="is_enabled" id="is_enabled">
                                                    <option value="1">Enabled</option>
                                                    <option value="0">Disabled</option>
                                                </select>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label d-block">Grant Type</label>
                                                <div class="form-check form-switch pt-2">
                                                    <input type="checkbox" class="form-check-input" id="is_one_time" name="is_one_time">
                                                    <label class="form-check-label" for="is_one_time">One-time grant</label>
                                                </div>
                                            </div>
                                        </div>

                                        <hr class="my-4">

                                        <h6 class="text-uppercase text-muted small mb-3">Disbursement</h6>
                                        <div class="mb-3">
                                            <label class="form-label">Money</label>
                                            <input type="number" class="form-control" name="money" id="grant_money" value="0" min="0">
                                        </div>

                                        <div class="row g-3">
                                            @foreach (PWHelperService::resources(false) as $resource)
                                                <div class="col-md-6">
                                                    <label class="form-label text-capitalize">{{ $resource }}</label>
                                                    <input type="number" class="form-control" name="{{ $resource }}"
                                                           id="grant_{{ $resource }}" value="0" min="0">
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-lg-7">
                                <div class="card shadow-sm border-0 h-100">
                                    <div class="card-header bg-transparent border-0 pb-0">
                                        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                                            <div>
                                                <h6 class="mb-1">Eligibility Builder</h6>
                                                <p class="text-muted small mb-0">Combine fields, comparisons, project checks, and nested logic without writing syntax.</p>
                                            </div>
                                            <div class="d-flex gap-2">
                                                <button type="button" class="btn btn-outline-primary btn-sm" id="addTopLevelCondition">
                                                    Add Condition
                                                </button>
                                                <button type="button" class="btn btn-outline-secondary btn-sm" id="addTopLevelGroup">
                                                    Add Group
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="card-body pt-3">
                                        @if ($oldGrantPayload && $errors->any())
                                            <div class="alert alert-danger mb-3">
                                                <div class="fw-semibold mb-2">Please fix the highlighted grant form issues.</div>
                                                <ul class="mb-0 ps-3 small">
                                                    @foreach ($errors->all() as $error)
                                                        <li>{{ $error }}</li>
                                                    @endforeach
                                                </ul>
                                            </div>
                                        @endif

                                        <div class="alert alert-light border mb-3">
                                            <div class="small text-uppercase text-muted mb-2">Live Summary</div>
                                            <div class="fw-semibold" id="requirementRuleCount">No custom requirements configured</div>
                                            <div class="small text-muted mt-2" id="requirementSummaryHint">Applications will only enforce the standard alliance and pending checks until you add rules.</div>
                                        </div>

                                        <div class="border rounded-3 bg-body-tertiary p-3 mb-3">
                                            <div class="d-flex justify-content-between align-items-center mb-2">
                                                <label class="form-label fw-semibold mb-0" for="root_group_mode">Top-level logic</label>
                                                <span class="badge text-bg-dark" id="rootRuleBadge">0 rules</span>
                                            </div>
                                            <select class="form-select" id="root_group_mode"></select>
                                        </div>

                                        <div id="grantRequirementBuilder" class="d-flex flex-column gap-3"></div>

                                        <div class="card mt-3 border-0 bg-body-tertiary">
                                            <div class="card-body">
                                                <div class="small text-uppercase text-muted mb-2">Builder Tips</div>
                                                <ul class="small text-muted mb-0 ps-3">
                                                    <li>Use <strong>Any condition may match</strong> when several different paths should qualify.</li>
                                                    <li>Use nested groups to combine project checks with city, score, or MMR ranges.</li>
                                                    <li>Add a custom message on any condition when you want a specific denial reason shown to the applicant.</li>
                                                </ul>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" class="btn btn-primary">Save Grant</button>
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
@endsection

@section('scripts')
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

            return {
                field: initialField,
                operator,
                value: defaultValueFor(field, operator),
                message: '',
            };
        }

        function createEmptyGroup(group = 'all') {
            return {group, rules: []};
        }

        function defaultValueFor(field, operator) {
            if (!field) {
                return '';
            }

            if (field.type === 'number') {
                return ['between', 'not_between'].includes(operator) ? {min: '', max: ''} : '';
            }

            if (field.type === 'enum') {
                if (['in', 'not_in'].includes(operator)) {
                    return [];
                }

                return field.options?.[0]?.value || '';
            }

            return [];
        }

        function ensureValidTree(tree) {
            if (!tree || typeof tree !== 'object' || Array.isArray(tree)) {
                return cloneData(defaultGrantRequirementTree);
            }

            const group = ['all', 'any', 'not'].includes(tree.group) ? tree.group : 'all';
            const rules = Array.isArray(tree.rules) ? tree.rules.map(ensureValidNode).filter(Boolean) : [];

            return {group, rules};
        }

        function ensureValidNode(node) {
            if (!node || typeof node !== 'object' || Array.isArray(node)) {
                return null;
            }

            if (Object.prototype.hasOwnProperty.call(node, 'group')) {
                return ensureValidTree(node);
            }

            const field = grantRequirementFields.get(node.field) || grantRequirementFields.get(grantRequirementBuilderConfig.fields[0]?.key);
            const operator = field.operators.includes(node.operator) ? node.operator : field.operators[0];

            return {
                field: field.key,
                operator,
                value: normalizeConditionValue(field, operator, node.value),
                message: typeof node.message === 'string' ? node.message : '',
            };
        }

        function normalizeConditionValue(field, operator, value) {
            if (field.type === 'number') {
                if (['between', 'not_between'].includes(operator)) {
                    return {
                        min: value && typeof value === 'object' ? value.min ?? '' : '',
                        max: value && typeof value === 'object' ? value.max ?? '' : '',
                    };
                }

                return value ?? '';
            }

            if (field.type === 'enum') {
                if (['in', 'not_in'].includes(operator)) {
                    return Array.isArray(value) ? value : [];
                }

                return value ?? field.options?.[0]?.value ?? '';
            }

            return Array.isArray(value) ? value : [];
        }

        function renderGrantRequirementBuilder() {
            const builder = document.getElementById('grantRequirementBuilder');
            builder.innerHTML = '';

            grantRequirementTree.rules.forEach((rule, index) => {
                builder.appendChild(renderNode(rule, [index], true));
            });

            syncGrantRequirementHiddenInput();
            updateGrantRequirementSummary();
        }

        function renderRootGroupModeSelect() {
            const select = document.getElementById('root_group_mode');

            if (!select) {
                return;
            }

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
            if (node.group) {
                return renderGroupNode(node, path, isRootLevel);
            }

            return renderConditionNode(node, path, isRootLevel);
        }

        function renderGroupNode(node, path, isRootLevel) {
            const card = document.createElement('div');
            card.className = 'card border border-secondary-subtle shadow-sm';

            const body = document.createElement('div');
            body.className = 'card-body';
            card.appendChild(body);

            const header = document.createElement('div');
            header.className = 'd-flex flex-wrap justify-content-between align-items-center gap-2 mb-3';
            body.appendChild(header);

            const labelWrap = document.createElement('div');
            header.appendChild(labelWrap);

            const label = document.createElement('div');
            label.className = 'fw-semibold';
            label.textContent = isRootLevel ? 'Nested Group' : 'Requirement Group';
            labelWrap.appendChild(label);

            const small = document.createElement('div');
            small.className = 'text-muted small';
            small.textContent = 'Use nested logic when one path or another should qualify.';
            labelWrap.appendChild(small);

            const actions = document.createElement('div');
            actions.className = 'd-flex flex-wrap gap-2';
            header.appendChild(actions);

            actions.appendChild(createButton('Condition', 'btn btn-outline-primary btn-sm', () => {
                node.rules.push(createEmptyCondition());
                renderGrantRequirementBuilder();
            }));

            actions.appendChild(createButton('Group', 'btn btn-outline-secondary btn-sm', () => {
                node.rules.push(createEmptyGroup('all'));
                renderGrantRequirementBuilder();
            }));

            actions.appendChild(createMoveButton('↑', path, -1));
            actions.appendChild(createMoveButton('↓', path, 1));

            if (!isRootLevel) {
                actions.appendChild(createButton('Remove', 'btn btn-outline-danger btn-sm', () => {
                    removeNodeAtPath(path);
                    renderGrantRequirementBuilder();
                }));
            }

            const select = document.createElement('select');
            select.className = 'form-select mb-3';
            grantRequirementBuilderConfig.groups.forEach(groupOption => {
                const option = document.createElement('option');
                option.value = groupOption.value;
                option.textContent = groupOption.label;
                option.selected = groupOption.value === node.group;
                select.appendChild(option);
            });
            select.addEventListener('change', event => {
                node.group = event.target.value;
                syncGrantRequirementHiddenInput();
                updateGrantRequirementSummary();
            });
            body.appendChild(select);

            if (node.rules.length === 0) {
                const empty = document.createElement('div');
                empty.className = 'border rounded-3 bg-light-subtle p-3 text-muted small';
                empty.textContent = 'This group is empty. Add a condition or subgroup to make it active.';
                body.appendChild(empty);
            } else {
                const children = document.createElement('div');
                children.className = 'd-flex flex-column gap-3';
                node.rules.forEach((child, childIndex) => {
                    children.appendChild(renderNode(child, [...path, childIndex]));
                });
                body.appendChild(children);
            }

            return card;
        }

        function renderConditionNode(node, path) {
            const field = grantRequirementFields.get(node.field) || grantRequirementFields.get(grantRequirementBuilderConfig.fields[0].key);
            const allowedOperators = field.operators.map(operatorKey => grantRequirementOperators.get(operatorKey));

            if (!field.operators.includes(node.operator)) {
                node.operator = field.operators[0];
                node.value = defaultValueFor(field, node.operator);
            }

            const card = document.createElement('div');
            card.className = 'card border-0 shadow-sm bg-body';

            const body = document.createElement('div');
            body.className = 'card-body';
            card.appendChild(body);

            const grid = document.createElement('div');
            grid.className = 'row g-3 align-items-start';
            body.appendChild(grid);

            const fieldCol = document.createElement('div');
            fieldCol.className = 'col-lg-4';
            grid.appendChild(fieldCol);
            fieldCol.appendChild(createFieldSelect(field, node));

            const operatorCol = document.createElement('div');
            operatorCol.className = 'col-lg-3';
            grid.appendChild(operatorCol);
            operatorCol.appendChild(createOperatorSelect(node, allowedOperators));

            const valueCol = document.createElement('div');
            valueCol.className = 'col-lg-5';
            grid.appendChild(valueCol);
            valueCol.appendChild(createValueEditor(field, node));

            const messageCol = document.createElement('div');
            messageCol.className = 'col-lg-9';
            grid.appendChild(messageCol);

            const messageLabel = document.createElement('label');
            messageLabel.className = 'form-label';
            messageLabel.textContent = 'Custom failure message';
            messageCol.appendChild(messageLabel);

            const messageInput = document.createElement('input');
            messageInput.type = 'text';
            messageInput.className = 'form-control';
            messageInput.placeholder = 'Optional. Shown to the applicant if this condition fails.';
            messageInput.value = node.message || '';
            messageInput.addEventListener('input', event => {
                node.message = event.target.value;
                syncGrantRequirementHiddenInput();
            });
            messageCol.appendChild(messageInput);

            const actionsCol = document.createElement('div');
            actionsCol.className = 'col-lg-3 d-flex justify-content-lg-end gap-2 pt-lg-4';
            grid.appendChild(actionsCol);
            actionsCol.appendChild(createMoveButton('↑', path, -1));
            actionsCol.appendChild(createMoveButton('↓', path, 1));
            actionsCol.appendChild(createButton('Remove', 'btn btn-outline-danger btn-sm', () => {
                removeNodeAtPath(path);
                renderGrantRequirementBuilder();
            }));

            return card;
        }

        function createFieldSelect(field, node) {
            const wrapper = document.createElement('div');
            const label = document.createElement('label');
            label.className = 'form-label';
            label.textContent = 'Field';
            wrapper.appendChild(label);

            const select = document.createElement('select');
            select.className = 'form-select';
            wrapper.appendChild(select);

            const grouped = {};
            grantRequirementBuilderConfig.fields.forEach(item => {
                grouped[item.category] = grouped[item.category] || [];
                grouped[item.category].push(item);
            });

            Object.entries(grouped).forEach(([category, items]) => {
                const group = document.createElement('optgroup');
                group.label = category;

                items.forEach(item => {
                    const option = document.createElement('option');
                    option.value = item.key;
                    option.textContent = item.label;
                    option.selected = item.key === field.key;
                    group.appendChild(option);
                });

                select.appendChild(group);
            });

            select.addEventListener('change', event => {
                const nextField = grantRequirementFields.get(event.target.value);
                node.field = nextField.key;
                node.operator = nextField.operators[0];
                node.value = defaultValueFor(nextField, node.operator);
                renderGrantRequirementBuilder();
            });

            return wrapper;
        }

        function createOperatorSelect(node, allowedOperators) {
            const wrapper = document.createElement('div');
            const label = document.createElement('label');
            label.className = 'form-label';
            label.textContent = 'Operator';
            wrapper.appendChild(label);

            const select = document.createElement('select');
            select.className = 'form-select';
            allowedOperators.forEach(operator => {
                const option = document.createElement('option');
                option.value = operator.value;
                option.textContent = operator.label;
                option.selected = operator.value === node.operator;
                select.appendChild(option);
            });
            select.addEventListener('change', event => {
                const field = grantRequirementFields.get(node.field);
                node.operator = event.target.value;
                node.value = defaultValueFor(field, node.operator);
                renderGrantRequirementBuilder();
            });

            wrapper.appendChild(select);

            return wrapper;
        }

        function createValueEditor(field, node) {
            const wrapper = document.createElement('div');
            const label = document.createElement('label');
            label.className = 'form-label';
            label.textContent = 'Value';
            wrapper.appendChild(label);

            if (field.type === 'number' && ['between', 'not_between'].includes(node.operator)) {
                const row = document.createElement('div');
                row.className = 'row g-2';
                wrapper.appendChild(row);

                row.appendChild(createNumberInput('Minimum', node.value?.min ?? '', value => {
                    node.value = {...(node.value || {}), min: value};
                    syncGrantRequirementHiddenInput();
                    updateGrantRequirementSummary();
                }));

                row.appendChild(createNumberInput('Maximum', node.value?.max ?? '', value => {
                    node.value = {...(node.value || {}), max: value};
                    syncGrantRequirementHiddenInput();
                    updateGrantRequirementSummary();
                }));

                return wrapper;
            }

            if (field.type === 'number') {
                const input = document.createElement('input');
                input.type = 'number';
                input.className = 'form-control';
                input.step = 'any';
                input.value = node.value ?? '';
                input.addEventListener('input', event => {
                    node.value = event.target.value;
                    syncGrantRequirementHiddenInput();
                    updateGrantRequirementSummary();
                });
                wrapper.appendChild(input);

                return wrapper;
            }

            if (field.type === 'enum' && ['eq', 'neq'].includes(node.operator)) {
                const select = document.createElement('select');
                select.className = 'form-select';
                field.options.forEach(optionData => {
                    const option = document.createElement('option');
                    option.value = optionData.value;
                    option.textContent = optionData.label;
                    option.selected = optionData.value === node.value;
                    select.appendChild(option);
                });
                select.addEventListener('change', event => {
                    node.value = event.target.value;
                    syncGrantRequirementHiddenInput();
                    updateGrantRequirementSummary();
                });
                wrapper.appendChild(select);

                return wrapper;
            }

            const select = document.createElement('select');
            select.className = 'form-select';
            select.multiple = true;
            select.size = Math.min(6, Math.max(4, field.options.length));
            const selectedValues = Array.isArray(node.value) ? node.value : [];

            field.options.forEach(optionData => {
                const option = document.createElement('option');
                option.value = optionData.value;
                option.textContent = optionData.label;
                option.selected = selectedValues.includes(optionData.value);
                select.appendChild(option);
            });

            select.addEventListener('change', event => {
                node.value = Array.from(event.target.selectedOptions).map(option => option.value);
                syncGrantRequirementHiddenInput();
                updateGrantRequirementSummary();
            });

            wrapper.appendChild(select);

            const help = document.createElement('div');
            help.className = 'form-text';
            help.textContent = 'Hold Command or Ctrl to select multiple values.';
            wrapper.appendChild(help);

            return wrapper;
        }

        function createNumberInput(labelText, value, onInput) {
            const col = document.createElement('div');
            col.className = 'col-6';

            const label = document.createElement('label');
            label.className = 'form-label small text-muted';
            label.textContent = labelText;
            col.appendChild(label);

            const input = document.createElement('input');
            input.type = 'number';
            input.className = 'form-control';
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
            button.className = 'btn btn-outline-dark btn-sm';
            button.textContent = label;
            button.addEventListener('click', () => {
                moveNodeAtPath(path, offset);
                renderGrantRequirementBuilder();
            });

            return button;
        }

        function getParentArray(path) {
            let target = grantRequirementTree.rules;

            for (let index = 0; index < path.length - 1; index++) {
                target = target[path[index]].rules;
            }

            return target;
        }

        function removeNodeAtPath(path) {
            const parent = getParentArray(path);
            parent.splice(path[path.length - 1], 1);
        }

        function moveNodeAtPath(path, offset) {
            const parent = getParentArray(path);
            const currentIndex = path[path.length - 1];
            const nextIndex = currentIndex + offset;

            if (nextIndex < 0 || nextIndex >= parent.length) {
                return;
            }

            const [item] = parent.splice(currentIndex, 1);
            parent.splice(nextIndex, 0, item);
        }

        function syncGrantRequirementHiddenInput() {
            const normalized = ensureValidTree(grantRequirementTree);
            const hasRules = normalized.rules.length > 0;

            document.getElementById('validation_rules_json').value = hasRules
                ? JSON.stringify(normalized)
                : '';
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
            const summaryLines = summarizeTree(grantRequirementTree);
            summaryTarget.textContent = summaryLines.slice(0, 3).join(' • ');
        }

        function countNodes(nodes) {
            return nodes.reduce((total, node) => {
                if (node.group) {
                    return total + 1 + countNodes(node.rules || []);
                }

                return total + 1;
            }, 0);
        }

        function summarizeTree(tree) {
            return (tree.rules || []).map(node => summarizeNode(node));
        }

        function summarizeNode(node) {
            if (node.group) {
                const labelMap = {
                    all: 'All of',
                    any: 'Any of',
                    not: 'None of',
                };

                return `${labelMap[node.group] || 'Group'}: ${(node.rules || []).map(summarizeNode).join('; ')}`;
            }

            const field = grantRequirementFields.get(node.field);
            const operator = grantRequirementOperators.get(node.operator);
            const label = field?.label || node.field;

            if (!field || !operator) {
                return label;
            }

            if (field.type === 'number') {
                if (['between', 'not_between'].includes(node.operator)) {
                    return `${label} ${operator.label.toLowerCase()} ${node.value?.min ?? '?'} and ${node.value?.max ?? '?'}`;
                }

                return `${label} ${operator.label.toLowerCase()} ${node.value ?? '?'}`;
            }

            if (Array.isArray(node.value)) {
                return `${label} ${operator.label.toLowerCase()} ${node.value.join(', ')}`;
            }

            return `${label} ${operator.label.toLowerCase()} ${node.value ?? '?'}`;
        }

        function populateForm(grant) {
            document.getElementById('grant_id').value = grant?.id || '';
            document.getElementById('grant_name').value = grant?.name || '';
            document.getElementById('grant_description').value = grant?.description || '';
            document.getElementById('is_enabled').value = grant?.is_enabled ? '1' : '0';
            document.getElementById('is_one_time').checked = !!grant?.is_one_time;
            document.getElementById('grant_money').value = grant?.money || 0;

            @foreach (PWHelperService::resources(false) as $resource)
                document.getElementById('grant_{{ $resource }}').value = grant?.{{ $resource }} || 0;
            @endforeach

            grantRequirementTree = ensureValidTree(grant?.validation_rules || defaultGrantRequirementTree);
            document.getElementById('root_group_mode').value = grantRequirementTree.group;
            renderGrantRequirementBuilder();
        }

        function editGrant(grant) {
            document.getElementById('grantForm').action = `/admin/grants/${grant.id}/update`;
            populateForm(grant);
        }

        function clearGrantForm() {
            document.getElementById('grantForm').action = `/admin/grants/create`;
            populateForm({
                is_enabled: true,
                is_one_time: false,
                money: 0,
                validation_rules: cloneData(defaultGrantRequirementTree),
                @foreach (PWHelperService::resources(false) as $resource)
                {{ $resource }}: 0,
                @endforeach
            });
        }

        document.addEventListener('DOMContentLoaded', () => {
            renderRootGroupModeSelect();

            document.getElementById('addTopLevelCondition').addEventListener('click', () => {
                grantRequirementTree.rules.push(createEmptyCondition());
                renderGrantRequirementBuilder();
            });

            document.getElementById('addTopLevelGroup').addEventListener('click', () => {
                grantRequirementTree.rules.push(createEmptyGroup('all'));
                renderGrantRequirementBuilder();
            });

            document.getElementById('root_group_mode').addEventListener('change', event => {
                grantRequirementTree.group = event.target.value;
                syncGrantRequirementHiddenInput();
                updateGrantRequirementSummary();
            });

            @if ($oldGrantPayload)
                document.getElementById('grantForm').action = "{{ $oldGrantPayload['id'] ? url('/admin/grants/'.$oldGrantPayload['id'].'/update') : url('/admin/grants/create') }}";
                populateForm(@json($oldGrantPayload));
                const grantModal = new bootstrap.Modal(document.getElementById('grantModal'));
                grantModal.show();
            @else
                clearGrantForm();
            @endif
        });
    </script>
@endsection
