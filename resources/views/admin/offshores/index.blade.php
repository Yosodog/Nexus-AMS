@php
    use Illuminate\Support\Str;

    $canManageOffshores = auth()->user()?->can('manage-offshores');
    $modalContext = old('modal_context');
    $showCreateModal = ($showCreateModal ?? false) || $modalContext === 'create';
    $editOffshoreId = $editOffshoreId ?? (Str::startsWith($modalContext, 'edit-') ? (int) Str::after($modalContext, 'edit-') : null);
    $mainBankSnapshot = $mainBankSnapshot ?? ['balances' => [], 'cached_at' => null];
    $mainBankCachedAt = $mainBankSnapshot['cached_at'] ?? null;
@endphp

@extends('layouts.admin')

@section('title', 'Offshore Management')

@section('content')
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col-sm-6">
                    <h3 class="mb-0">Offshore Management</h3>
                    <p class="text-muted small mb-0">Monitor cached balances, adjust guardrails, and trigger manual transfers.</p>
                </div>
                @if($canManageOffshores)
                    <div class="col-sm-6 text-sm-end mt-3 mt-sm-0">
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createOffshoreModal">
                            <i class="bi bi-plus-circle me-1"></i> Add Offshore
                        </button>
                    </div>
                @endif
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-12 col-xl-8">
            <div class="card shadow-sm">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Configured Offshores</h5>
                    @if($canManageOffshores)
                        <button class="btn btn-outline-primary btn-sm" type="submit" form="offshore-priority-form">
                            <i class="bi bi-arrow-repeat me-1"></i> Save Priority Order
                        </button>
                    @endif
                </div>

                <div class="card-body">
                    <div class="table-responsive">
                        <form id="offshore-priority-form" action="{{ route('admin.offshores.reorder') }}" method="POST" class="d-none">
                            @csrf
                        </form>

                        <table class="table table-hover align-middle">
                                <thead class="table-light">
                                <tr>
                                    <th style="width: 90px;">Priority</th>
                                    <th>Name</th>
                                    <th>Alliance</th>
                                    <th>Status</th>
                                    <th>Cached Balances</th>
                                    <th>Guardrails</th>
                                    @if($canManageOffshores)
                                        <th class="text-end">Actions</th>
                                    @endif
                                </tr>
                                </thead>
                                <tbody>
                                @forelse($offshores as $offshore)
                                    @php
                                        $snapshot = $snapshots[$offshore->id] ?? ['balances' => [], 'cached_at' => null];
                                        $cachedAt = $snapshot['cached_at'];
                                    @endphp
                                    <tr>
                                        <td>
                                            @if($canManageOffshores)
                                                <input type="number"
                                                       name="order[{{ $offshore->id }}]"
                                                       value="{{ old('order.' . $offshore->id, $offshore->priority) }}"
                                                       class="form-control form-control-sm"
                                                       min="0"
                                                       aria-label="Priority for {{ $offshore->name }}"
                                                       form="offshore-priority-form">
                                            @else
                                                <span class="badge text-bg-secondary">{{ $offshore->priority }}</span>
                                            @endif
                                        </td>
                                        <td>
                                            <div class="fw-semibold">{{ $offshore->name }}</div>
                                            <div class="text-muted small">Created {{ $offshore->created_at?->format('M d, Y') ?? 'Unknown' }}</div>
                                        </td>
                                        <td>
                                            <a href="https://politicsandwar.com/alliance/id={{ $offshore->alliance_id }}"
                                               target="_blank"
                                               class="text-decoration-none">
                                                <i class="bi bi-box-arrow-up-right me-1"></i>{{ $offshore->alliance_id }}
                                            </a>
                                        </td>
                                        <td>
                                            @if($offshore->enabled)
                                                <span class="badge text-bg-success">Enabled</span>
                                            @else
                                                <span class="badge text-bg-secondary">Disabled</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if(!empty($snapshot['balances']))
                                                <div class="small text-muted">
                                                    Cached {{ $cachedAt ? $cachedAt->diffForHumans() : 'recently' }}
                                                </div>
                                                <div class="d-flex flex-wrap gap-2 mt-2">
                                                    @foreach($snapshot['balances'] as $resource => $amount)
                                                        <span class="badge text-bg-light text-capitalize">
                                                            {{ $resource }}:
                                                            {{ $resource === 'money' ? '$' . number_format($amount, 2) : number_format($amount, 2) }}
                                                        </span>
                                                    @endforeach
                                                </div>
                                            @else
                                                <span class="text-muted">No cached balances yet.</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if($offshore->guardrails->isNotEmpty())
                                                <ul class="list-unstyled mb-0 small">
                                                    @foreach($offshore->guardrails as $guardrail)
                                                        <li>
                                                            <span class="text-capitalize">{{ $guardrail->resource }}</span> ≥
                                                            {{ number_format($guardrail->minimum_amount, 2) }}
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            @else
                                                <span class="text-muted">No guardrails</span>
                                            @endif
                                        </td>
                                        @if($canManageOffshores)
                                            <td class="text-end">
                                                <div class="btn-group btn-group-sm" role="group" aria-label="Actions for {{ $offshore->name }}">
                                                    <button class="btn btn-outline-secondary"
                                                            type="button"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#editOffshoreModal-{{ $offshore->id }}"
                                                            data-bs-tooltip="true"
                                                            data-bs-placement="top"
                                                            title="Edit offshore">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <form action="{{ route('admin.offshores.refresh', $offshore) }}" method="POST" class="d-inline">
                                                        @csrf
                                                        <button type="submit"
                                                                class="btn btn-outline-primary"
                                                                data-bs-toggle="tooltip"
                                                                data-bs-placement="top"
                                                                title="Refresh balances">
                                                            <i class="bi bi-arrow-clockwise"></i>
                                                        </button>
                                                    </form>
                                                    <button type="button"
                                                            class="btn btn-outline-info"
                                                            data-action="open-transfer"
                                                            data-source-type="{{ \App\Models\OffshoreTransfer::TYPE_OFFSHORE }}"
                                                            data-source-id="{{ $offshore->id }}"
                                                            data-destination-type="{{ \App\Models\OffshoreTransfer::TYPE_MAIN }}"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#manualTransferModal"
                                                            data-bs-tooltip="true"
                                                            data-bs-placement="top"
                                                            title="Transfer to main bank">
                                                        <i class="bi bi-upload"></i>
                                                    </button>
                                                    <button type="button"
                                                            class="btn btn-outline-success"
                                                            data-action="open-transfer"
                                                            data-source-type="{{ \App\Models\OffshoreTransfer::TYPE_MAIN }}"
                                                            data-destination-type="{{ \App\Models\OffshoreTransfer::TYPE_OFFSHORE }}"
                                                            data-destination-id="{{ $offshore->id }}"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#manualTransferModal"
                                                            data-bs-tooltip="true"
                                                            data-bs-placement="top"
                                                            title="Send funds from main bank">
                                                        <i class="bi bi-download"></i>
                                                    </button>
                                                    <form action="{{ route('admin.offshores.sweep', $offshore) }}" method="POST" class="d-inline"
                                                          onsubmit="return confirm('Sweep the entire main bank into {{ $offshore->name }}? This cannot be undone.');">
                                                        @csrf
                                                        <button type="submit"
                                                                class="btn btn-outline-dark"
                                                                data-bs-toggle="tooltip"
                                                                data-bs-placement="top"
                                                                title="Sweep entire main bank into this offshore">
                                                            <i class="bi bi-bank"></i>
                                                        </button>
                                                    </form>
                                                    <form action="{{ route('admin.offshores.toggle', $offshore) }}" method="POST" class="d-inline">
                                                        @csrf
                                                        <button type="submit"
                                                                class="btn btn-outline-warning"
                                                                data-bs-toggle="tooltip"
                                                                data-bs-placement="top"
                                                                title="Toggle availability">
                                                            <i class="bi bi-power"></i>
                                                        </button>
                                                    </form>
                                                    <form action="{{ route('admin.offshores.destroy', $offshore) }}" method="POST" class="d-inline"
                                                          onsubmit="return confirm('Delete {{ $offshore->name }}? This action cannot be undone.');">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit"
                                                                class="btn btn-outline-danger"
                                                                data-bs-toggle="tooltip"
                                                                data-bs-placement="top"
                                                                title="Delete offshore">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        @endif
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="text-center text-muted py-4">
                                            No offshores configured yet.
                                        </td>
                                    </tr>
                                @endforelse
                                </tbody>
                            </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-4">
            <div class="card shadow-sm h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Main Bank Snapshot</h5>
                    @if($canManageOffshores)
                        <form action="{{ route('admin.offshores.main-bank.refresh') }}" method="POST" class="ms-2">
                            @csrf
                            <button type="submit" class="btn btn-outline-primary btn-sm">
                                <i class="bi bi-arrow-clockwise me-1"></i> Refresh
                            </button>
                        </form>
                    @endif
                </div>
                <div class="card-body">
                    @php
                        $visibleMainBalances = collect($mainBankSnapshot['balances'] ?? [])->filter(fn($amount) => $amount > 0);
                    @endphp
                    @if($visibleMainBalances->isNotEmpty())
                        <div class="text-muted small">
                            Cached {{ $mainBankCachedAt ? $mainBankCachedAt->diffForHumans() : 'recently' }}
                        </div>
                        <div class="d-flex flex-wrap gap-2 mt-2">
                            @foreach($visibleMainBalances as $resource => $amount)
                                <span class="badge text-bg-light text-capitalize">
                                    {{ $resource }}:
                                    {{ $resource === 'money' ? '$' . number_format($amount, 2) : number_format($amount, 2) }}
                                </span>
                            @endforeach
                        </div>
                    @else
                        <p class="text-muted mb-0">No cached main bank data yet.</p>
                    @endif
                </div>
            </div>
        </div>

        @if($canManageOffshores)
            <div class="col-lg-5">
                <div class="card shadow-sm h-100">
                    <div class="card-header">
                        <h5 class="mb-0">Manual Transfer</h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted small">Bridge funds between the main bank and offshores. Transfers are executed instantly using the configured API keys.</p>
                        <button class="btn btn-outline-primary w-100" data-bs-toggle="modal" data-bs-target="#manualTransferModal">
                            <i class="bi bi-cash-coin me-1"></i> Start Transfer
                        </button>
                    </div>
                </div>
            </div>
            <div class="col-lg-7">
        @else
            <div class="col-12">
        @endif
                <div class="card shadow-sm h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Recent Manual Transfers</h5>
                        <span class="text-muted small">Last {{ $transfers->count() }} records</span>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped align-middle mb-0">
                                <thead>
                                <tr>
                                    <th>When</th>
                                    <th>Initiated By</th>
                                    <th>Route</th>
                                    <th>Payload</th>
                                    <th>Status</th>
                                </tr>
                                </thead>
                                <tbody>
                                @forelse($transfers as $transfer)
                                    <tr>
                                        <td>{{ $transfer->created_at?->format('M d, Y H:i') ?? 'Unknown' }}</td>
                                        <td>{{ $transfer->user?->name ?? 'Unknown User' }}</td>
                                        <td>
                                            {{ $transfer->source_type === \App\Models\OffshoreTransfer::TYPE_MAIN ? 'Main Bank' : ($transfer->sourceOffshore?->name ?? 'Offshore') }}
                                            <i class="bi bi-arrow-right-short"></i>
                                            {{ $transfer->destination_type === \App\Models\OffshoreTransfer::TYPE_MAIN ? 'Main Bank' : ($transfer->destinationOffshore?->name ?? 'Offshore') }}
                                        </td>
                                        <td>
                                            <ul class="list-inline mb-0 small">
                                                @foreach($transfer->payload as $resource => $amount)
                                                    <li class="list-inline-item text-capitalize">
                                                        <span class="badge text-bg-light">
                                                            {{ $resource }}:
                                                            {{ $resource === 'money' ? '$' . number_format($amount, 2) : number_format($amount, 2) }}
                                                        </span>
                                                    </li>
                                                @endforeach
                                            </ul>
                                            @if($transfer->message)
                                                <div class="small text-muted mt-1">{{ $transfer->message }}</div>
                                            @endif
                                        </td>
                                        <td>
                                            @switch($transfer->status)
                                                @case(\App\Models\OffshoreTransfer::STATUS_COMPLETED)
                                                    <span class="badge text-bg-success">Completed</span>
                                                    @break
                                                @case(\App\Models\OffshoreTransfer::STATUS_FAILED)
                                                    <span class="badge text-bg-danger">Failed</span>
                                                    @break
                                                @default
                                                    <span class="badge text-bg-secondary">Pending</span>
                                            @endswitch
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="text-center text-muted py-4">No transfers recorded yet.</td>
                                    </tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
    </div>

    @if($canManageOffshores)
        {{-- Create Offshore Modal --}}
        <div class="modal fade" id="createOffshoreModal" tabindex="-1" aria-labelledby="createOffshoreModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <form action="{{ route('admin.offshores.store') }}" method="POST" autocomplete="off">
                        @csrf
                        <input type="hidden" name="modal_context" value="create">
                        <div class="modal-header">
                            <h5 class="modal-title" id="createOffshoreModalLabel">Add Offshore</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Name</label>
                                    <input type="text" class="form-control" name="name" value="{{ $modalContext === 'create' ? old('name') : '' }}" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Alliance ID</label>
                                    <input type="number" class="form-control" name="alliance_id" value="{{ $modalContext === 'create' ? old('alliance_id') : '' }}" min="1" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">API Key</label>
                                    <input type="text" class="form-control" name="api_key" value="{{ $modalContext === 'create' ? old('api_key') : '' }}" required>
                                    <div class="form-text">Stored encrypted. Paste the offshore bot API key.</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Mutation Key</label>
                                    <input type="text" class="form-control" name="mutation_key" value="{{ $modalContext === 'create' ? old('mutation_key') : '' }}" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Priority</label>
                                    <input type="number" class="form-control" name="priority" value="{{ $modalContext === 'create' ? old('priority', 0) : 0 }}" min="0">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Enabled</label>
                                    @php $createEnabled = $modalContext === 'create' ? (int) old('enabled', 1) : 1 @endphp
                                    <select class="form-select" name="enabled">
                                        <option value="1" {{ $createEnabled === 1 ? 'selected' : '' }}>Yes</option>
                                        <option value="0" {{ $createEnabled === 0 ? 'selected' : '' }}>No</option>
                                    </select>
                                </div>
                            </div>

                            <hr class="my-4">

                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Guardrails</h6>
                                <button class="btn btn-sm btn-outline-secondary" type="button"
                                        data-action="add-guardrail" data-target="#create-guardrail-container">
                                    <i class="bi bi-plus-circle me-1"></i> Add Guardrail
                                </button>
                            </div>
                            @php $createGuardrails = $modalContext === 'create' ? old('guardrails', []) : [] @endphp
                            <input type="hidden" name="guardrails" value="">
                            <div id="create-guardrail-container" class="guardrail-container" data-next-index="{{ count($createGuardrails) }}">
                                @foreach($createGuardrails as $index => $guardrail)
                                    @include('admin.offshores.partials.guardrail-row', ['index' => $index, 'guardrail' => $guardrail, 'resources' => $guardrailResources])
                                @endforeach
                            </div>
                            <p class="text-muted small mt-2">Guardrails prevent automated withdrawals from dropping a resource below the specified minimum.</p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">Save Offshore</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        {{-- Edit Modals --}}
        @foreach($offshores as $offshore)
            <div class="modal fade" id="editOffshoreModal-{{ $offshore->id }}" tabindex="-1" aria-labelledby="editOffshoreModalLabel-{{ $offshore->id }}" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-scrollable">
                    <div class="modal-content">
                        <form action="{{ route('admin.offshores.update', $offshore) }}" method="POST" autocomplete="off">
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="modal_context" value="edit-{{ $offshore->id }}">
                            <div class="modal-header">
                                <h5 class="modal-title" id="editOffshoreModalLabel-{{ $offshore->id }}">Edit {{ $offshore->name }}</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                @php $editContext = $modalContext === 'edit-' . $offshore->id @endphp
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Name</label>
                                        <input type="text" class="form-control" name="name" value="{{ $editContext ? old('name', $offshore->name) : $offshore->name }}">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Alliance ID</label>
                                        <input type="number" class="form-control" name="alliance_id" value="{{ $editContext ? old('alliance_id', $offshore->alliance_id) : $offshore->alliance_id }}" min="1">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">API Key</label>
                                        <input type="text" class="form-control" name="api_key" placeholder="Leave blank to keep current key">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Mutation Key</label>
                                        <input type="text" class="form-control" name="mutation_key" placeholder="Leave blank to keep current key">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Priority</label>
                                        <input type="number" class="form-control" name="priority" value="{{ $editContext ? old('priority', $offshore->priority) : $offshore->priority }}" min="0">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Enabled</label>
                                        @php $editEnabled = $editContext ? (int) old('enabled', $offshore->enabled ? 1 : 0) : ($offshore->enabled ? 1 : 0) @endphp
                                        <select class="form-select" name="enabled">
                                            <option value="1" {{ $editEnabled === 1 ? 'selected' : '' }}>Yes</option>
                                            <option value="0" {{ $editEnabled === 0 ? 'selected' : '' }}>No</option>
                                        </select>
                                    </div>
                                </div>

                                <hr class="my-4">

                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <h6 class="mb-0">Guardrails</h6>
                                    <button class="btn btn-sm btn-outline-secondary" type="button"
                                            data-action="add-guardrail" data-target="#edit-guardrail-container-{{ $offshore->id }}">
                                        <i class="bi bi-plus-circle me-1"></i> Add Guardrail
                                    </button>
                                </div>
                                @php
                                    $editGuardrails = $editContext
                                        ? old('guardrails', [])
                                        : $offshore->guardrails->map(fn($guardrail) => [
                                            'resource' => $guardrail->resource,
                                            'minimum_amount' => $guardrail->minimum_amount,
                                        ])->all();
                                @endphp
                                <input type="hidden" name="guardrails" value="">
                                <div id="edit-guardrail-container-{{ $offshore->id }}" class="guardrail-container"
                                     data-next-index="{{ count($editGuardrails) }}">
                                    @foreach($editGuardrails as $index => $guardrail)
                                        @include('admin.offshores.partials.guardrail-row', ['index' => $index, 'guardrail' => $guardrail, 'resources' => $guardrailResources])
                                    @endforeach
                                </div>
                                <p class="text-muted small mt-2">Leave guardrails empty to allow the service to manage resources freely.</p>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-primary">Save Changes</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        @endforeach

        {{-- Manual Transfer Modal --}}
        <div class="modal fade" id="manualTransferModal" tabindex="-1" aria-labelledby="manualTransferModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <form action="{{ route('admin.offshores.transfer') }}" method="POST" autocomplete="off">
                        @csrf
                        <div class="modal-header">
                            <h5 class="modal-title" id="manualTransferModalLabel">Manual Offshore Transfer</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Source</label>
                                    <select class="form-select" name="source_type" id="transfer-source-type" required>
                                        <option value="{{ \App\Models\OffshoreTransfer::TYPE_MAIN }}">Main Bank</option>
                                        @foreach($offshores as $offshore)
                                            <option value="{{ \App\Models\OffshoreTransfer::TYPE_OFFSHORE }}" data-offshore-id="{{ $offshore->id }}">
                                                {{ $offshore->name }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <input type="hidden" name="source_offshore_id" id="transfer-source-id">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Destination</label>
                                    <select class="form-select" name="destination_type" id="transfer-destination-type" required>
                                        <option value="{{ \App\Models\OffshoreTransfer::TYPE_MAIN }}">Main Bank</option>
                                        @foreach($offshores as $offshore)
                                            <option value="{{ \App\Models\OffshoreTransfer::TYPE_OFFSHORE }}" data-offshore-id="{{ $offshore->id }}">
                                                {{ $offshore->name }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <input type="hidden" name="destination_offshore_id" id="transfer-destination-id">
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Operator Note <span class="text-muted small">(optional)</span></label>
                                    <input type="text" class="form-control" name="note" placeholder="Visible in bank records and audit logs">
                                </div>
                            </div>

                            <hr class="my-4">

                            <div class="row g-3">
                                @foreach($resources as $resource)
                                    <div class="col-md-4">
                                        <label class="form-label text-capitalize">{{ $resource }}</label>
                                        <div class="input-group">
                                            @if($resource === 'money')
                                                <span class="input-group-text">$</span>
                                            @endif
                                            <input type="number" step="0.01" min="0" class="form-control" name="resources[{{ $resource }}]" placeholder="0.00">
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                            <p class="text-muted small mt-2">Only resources with amounts greater than zero will be transferred.</p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">Execute Transfer</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    {{-- Guardrail Row Template --}}
    <template id="guardrail-row-template">
        <div class="row g-2 align-items-end guardrail-row mb-2">
            <div class="col-md-5">
                <label class="form-label">Resource</label>
                <select class="form-select" name="guardrails[__INDEX__][resource]" required>
                    @foreach($guardrailResources as $resource)
                        <option value="{{ $resource }}">{{ ucfirst($resource) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-5">
                <label class="form-label">Minimum Amount</label>
                <input type="number" step="0.01" min="0" class="form-control" name="guardrails[__INDEX__][minimum_amount]" required>
            </div>
            <div class="col-md-2 text-end">
                <button type="button" class="btn btn-outline-danger btn-sm" data-action="remove-guardrail">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
        </div>
    </template>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            if (!window.bootstrap || !bootstrap.Tooltip) {
                return;
            }

            const tooltipTriggerList = Array.from(document.querySelectorAll('[data-bs-toggle="tooltip"], [data-bs-tooltip="true"]'));

            tooltipTriggerList.forEach((tooltipTriggerEl) => {
                new bootstrap.Tooltip(tooltipTriggerEl);
            });
        });
    </script>
@endpush

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const guardrailTemplate = document.getElementById('guardrail-row-template');

            document.querySelectorAll('[data-action="add-guardrail"]').forEach(button => {
                button.addEventListener('click', () => {
                    const targetSelector = button.dataset.target;
                    const container = targetSelector ? document.querySelector(targetSelector) : null;

                    if (!container || !guardrailTemplate) {
                        return;
                    }

                    const nextIndex = parseInt(container.dataset.nextIndex ?? container.querySelectorAll('.guardrail-row').length, 10);
                    const content = guardrailTemplate.innerHTML.replace(/__INDEX__/g, String(nextIndex));
                    container.insertAdjacentHTML('beforeend', content);
                    container.dataset.nextIndex = String(nextIndex + 1);
                });
            });

            document.addEventListener('click', event => {
                const removeButton = event.target.closest('[data-action="remove-guardrail"]');

                if (removeButton) {
                    const row = removeButton.closest('.guardrail-row');
                    if (row) {
                        row.remove();
                    }
                }
            });

            // Synchronize hidden inputs when opening the transfer modal.
            document.querySelectorAll('[data-action="open-transfer"]').forEach(button => {
                button.addEventListener('click', () => {
                    const sourceType = button.dataset.sourceType;
                    const destinationType = button.dataset.destinationType;
                    const sourceId = button.dataset.sourceId ?? '';
                    const destinationId = button.dataset.destinationId ?? '';

                    const sourceTypeSelect = document.getElementById('transfer-source-type');
                    const destinationTypeSelect = document.getElementById('transfer-destination-type');
                    const sourceIdInput = document.getElementById('transfer-source-id');
                    const destinationIdInput = document.getElementById('transfer-destination-id');

                    if (sourceTypeSelect) {
                        sourceTypeSelect.value = sourceType ?? sourceTypeSelect.value;
                    }

                    if (destinationTypeSelect) {
                        destinationTypeSelect.value = destinationType ?? destinationTypeSelect.value;
                    }

                    if (sourceIdInput) {
                        sourceIdInput.value = sourceId;
                    }

                    if (destinationIdInput) {
                        destinationIdInput.value = destinationId;
                    }
                });
            });

            const transferModal = document.getElementById('manualTransferModal');
            if (transferModal) {
                transferModal.addEventListener('show.bs.modal', () => {
                    const sourceTypeSelect = document.getElementById('transfer-source-type');
                    const destinationTypeSelect = document.getElementById('transfer-destination-type');
                    const sourceIdInput = document.getElementById('transfer-source-id');
                    const destinationIdInput = document.getElementById('transfer-destination-id');

                    const updateHiddenInputs = (selectElement, hiddenInput) => {
                        if (!selectElement || !hiddenInput) {
                            return;
                        }

                        const selectedOption = selectElement.selectedOptions[0];
                        const offshoreId = selectedOption ? selectedOption.dataset.offshoreId : '';
                        hiddenInput.value = offshoreId ?? '';
                    };

                    sourceTypeSelect?.addEventListener('change', () => updateHiddenInputs(sourceTypeSelect, sourceIdInput));
                    destinationTypeSelect?.addEventListener('change', () => updateHiddenInputs(destinationTypeSelect, destinationIdInput));

                    updateHiddenInputs(sourceTypeSelect, sourceIdInput);
                    updateHiddenInputs(destinationTypeSelect, destinationIdInput);
                });
            }

            // Automatically open the relevant modal when validation errors occur.
            @if($showCreateModal)
            const createModal = new bootstrap.Modal(document.getElementById('createOffshoreModal'));
            createModal.show();
            @elseif($editOffshoreId)
            const editModalElement = document.getElementById('editOffshoreModal-{{ $editOffshoreId }}');
            if (editModalElement) {
                const editModal = new bootstrap.Modal(editModalElement);
                editModal.show();
            }
            @endif
        });
    </script>
@endpush
