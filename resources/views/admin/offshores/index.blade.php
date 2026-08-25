@php
    use Illuminate\Support\Str;

    $canManageOffshores = auth()->user()?->can('manage-offshores');
    $modalContext = old('modal_context');
    $mainBankCredentialContext = $modalContext === 'main-bank-credentials';
    $showCreateModal = ($showCreateModal ?? false) || $modalContext === 'create';
    $editOffshoreId = $editOffshoreId ?? (Str::startsWith($modalContext, 'edit-') ? (int) Str::after($modalContext, 'edit-') : null);
    $mainBankSnapshot = $mainBankSnapshot ?? ['balances' => [], 'cached_at' => null];
    $mainBankCachedAt = $mainBankSnapshot['cached_at'] ?? null;
    $mainBankCredentialStatus = $mainBankCredentialStatus ?? [
        'api_key_configured' => false,
        'api_key_source' => null,
        'mutation_key_configured' => false,
        'mutation_key_source' => null,
    ];
@endphp

@extends('layouts.admin')

@section('title', 'Offshore Management')

@section('content')
    <x-header title="Offshore Management" separator use-h1>
        <x-slot:subtitle>Monitor cached balances, manage credentials and guardrails, and trigger manual transfers.</x-slot:subtitle>
        @if($canManageOffshores)
            <x-slot:actions>
                <button id="offshore-create-modal-open" class="btn btn-primary btn-sm" type="button" data-dialog-open="createOffshoreModal">
                    <x-icon name="o-plus-circle" class="size-4" />
                    Add Offshore
                </button>
            </x-slot:actions>
        @endif
    </x-header>

    <div class="space-y-6">
        <x-card title="Configured Offshores">
            <x-slot:menu>
                @if($canManageOffshores)
                    <button id="offshore-priority-order-submit" class="btn btn-outline btn-sm" type="submit" form="offshore-priority-form">
                        <x-icon name="o-arrow-path" class="size-4" />
                        Save Priority Order
                    </button>
                @endif
            </x-slot:menu>

            <form id="offshore-priority-form" action="{{ route('admin.offshores.reorder') }}" method="POST" class="hidden">
                @csrf
            </form>

            <div class="overflow-x-auto rounded-box border border-base-300">
                <table class="table table-zebra" data-sortable="false">
                    <thead>
                    <tr>
                        <th>Priority</th>
                        <th>Name</th>
                        <th>Alliance</th>
                        <th>Status</th>
                        <th>Cached Balances</th>
                        <th>Guardrails</th>
                        @if($canManageOffshores)
                            <th class="text-right" data-sortable="false">Actions</th>
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
                                    <input
                                        id="offshore-priority-{{ $offshore->id }}"
                                        type="number"
                                        name="order[{{ $offshore->id }}]"
                                        value="{{ old('order.' . $offshore->id, $offshore->priority) }}"
                                        class="input input-sm w-24"
                                        min="0"
                                        aria-label="Priority for {{ $offshore->name }}"
                                        aria-invalid="{{ $errors->has('order.'.$offshore->id) ? 'true' : 'false' }}"
                                        @if($errors->has('order.'.$offshore->id)) aria-describedby="offshore-priority-{{ $offshore->id }}-error" @endif
                                        form="offshore-priority-form"
                                    >
                                    @error('order.'.$offshore->id)
                                        <span id="offshore-priority-{{ $offshore->id }}-error" class="block text-xs text-error">{{ $message }}</span>
                                    @enderror
                                @else
                                    <span class="badge badge-ghost">{{ $offshore->priority }}</span>
                                @endif
                            </td>
                            <td>
                                <div class="font-semibold">{{ $offshore->name }}</div>
                                <div class="text-sm nexus-text-muted">Created {{ $offshore->created_at?->format('M d, Y') ?? 'Unknown' }}</div>
                            </td>
                            <td>
                                <a
                                    href="https://politicsandwar.com/alliance/id={{ $offshore->alliance_id }}"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="link link-hover"
                                >
                                    Alliance #{{ $offshore->alliance_id }}
                                </a>
                            </td>
                            <td>
                                <span class="badge {{ $offshore->enabled ? 'badge-success' : 'badge-ghost' }}">
                                    {{ $offshore->enabled ? 'Enabled' : 'Disabled' }}
                                </span>
                            </td>
                            <td>
                                @if(! empty($snapshot['balances']))
                                    <div class="text-sm nexus-text-muted">
                                        Cached {{ $cachedAt ? $cachedAt->diffForHumans() : 'recently' }}
                                    </div>
                                    <div class="mt-2 flex max-w-xl flex-wrap gap-2">
                                        @foreach($snapshot['balances'] as $resource => $amount)
                                            <span class="badge badge-outline whitespace-normal break-words py-3 text-left">
                                                {{ $resource }}:
                                                {{ $resource === 'money' ? '$' . number_format($amount, 2) : number_format($amount, 2) }}
                                            </span>
                                        @endforeach
                                    </div>
                                @else
                                    <span class="text-sm nexus-text-muted">No cached balances yet.</span>
                                @endif
                            </td>
                            <td>
                                @if($offshore->guardrails->isNotEmpty())
                                    <div class="flex flex-wrap gap-2">
                                        @foreach($offshore->guardrails as $guardrail)
                                            <span class="badge badge-ghost whitespace-normal break-words py-3 text-left">
                                                {{ ucfirst($guardrail->resource) }} ≥ {{ number_format($guardrail->minimum_amount, 2) }}
                                            </span>
                                        @endforeach
                                    </div>
                                @else
                                    <span class="text-sm nexus-text-muted">No guardrails</span>
                                @endif
                            </td>
                            @if($canManageOffshores)
                                <td class="text-right">
                                    @php
                                        $sweepConfirmation = "Sweep the entire main bank into {$offshore->name}? This cannot be undone.";
                                        $deleteConfirmation = "Delete {$offshore->name}? This action cannot be undone.";
                                    @endphp
                                    <div class="flex flex-wrap justify-end gap-2">
                                        <div class="tooltip tooltip-left" data-tip="Edit {{ $offshore->name }}">
                                            <button id="offshore-{{ $offshore->id }}-edit-modal-open" class="btn btn-outline btn-sm" type="button" data-dialog-open="editOffshoreModal-{{ $offshore->id }}" aria-label="Edit {{ $offshore->name }}">
                                                <x-icon name="o-pencil" class="size-4" />
                                            </button>
                                        </div>
                                        <form action="{{ route('admin.offshores.refresh', $offshore) }}" method="POST" class="tooltip tooltip-left" data-tip="Refresh balances for {{ $offshore->name }}">
                                            @csrf
                                            <button id="offshore-{{ $offshore->id }}-balances-refresh" type="submit" class="btn btn-outline btn-primary btn-sm" aria-label="Refresh balances for {{ $offshore->name }}">
                                                <x-icon name="o-arrow-path" class="size-4" />
                                            </button>
                                        </form>
                                        <div class="tooltip tooltip-left" data-tip="Transfer funds from {{ $offshore->name }} to the main bank">
                                            <button
                                                id="offshore-{{ $offshore->id }}-transfer-to-main-open"
                                                type="button"
                                                class="btn btn-outline btn-info btn-sm"
                                                data-action="open-transfer"
                                                data-source-type="{{ \App\Models\OffshoreTransfer::TYPE_OFFSHORE }}"
                                                data-source-id="{{ $offshore->id }}"
                                                data-destination-type="{{ \App\Models\OffshoreTransfer::TYPE_MAIN }}"
                                                aria-label="Transfer funds from {{ $offshore->name }} to the main bank"
                                            >
                                                <x-icon name="o-arrow-up-tray" class="size-4" />
                                            </button>
                                        </div>
                                        <div class="tooltip tooltip-left" data-tip="Send funds from the main bank to {{ $offshore->name }}">
                                            <button
                                                id="offshore-{{ $offshore->id }}-transfer-from-main-open"
                                                type="button"
                                                class="btn btn-outline btn-success btn-sm"
                                                data-action="open-transfer"
                                                data-source-type="{{ \App\Models\OffshoreTransfer::TYPE_MAIN }}"
                                                data-destination-type="{{ \App\Models\OffshoreTransfer::TYPE_OFFSHORE }}"
                                                data-destination-id="{{ $offshore->id }}"
                                                aria-label="Send funds from the main bank to {{ $offshore->name }}"
                                            >
                                                <x-icon name="o-arrow-down-tray" class="size-4" />
                                            </button>
                                        </div>
                                        <form action="{{ route('admin.offshores.sweep', $offshore) }}" method="POST" class="tooltip tooltip-left" data-tip="Sweep the main bank into {{ $offshore->name }}" data-confirm="{{ $sweepConfirmation }}" data-confirm-title="Sweep offshore funds?" data-confirm-label="Sweep funds">
                                            @csrf
                                            <button id="offshore-{{ $offshore->id }}-sweep" type="submit" class="btn btn-outline btn-neutral btn-sm" aria-label="Sweep the main bank into {{ $offshore->name }}">
                                                <x-icon name="o-building-library" class="size-4" />
                                            </button>
                                        </form>
                                        <form action="{{ route('admin.offshores.toggle', $offshore) }}" method="POST" class="tooltip tooltip-left" data-tip="{{ $offshore->enabled ? 'Disable' : 'Enable' }} {{ $offshore->name }}">
                                            @csrf
                                            <button id="offshore-{{ $offshore->id }}-enabled-toggle" type="submit" class="btn btn-outline btn-warning btn-sm" aria-label="{{ $offshore->enabled ? 'Disable' : 'Enable' }} {{ $offshore->name }}">
                                                <x-icon name="o-power" class="size-4" />
                                            </button>
                                        </form>
                                        <form action="{{ route('admin.offshores.destroy', $offshore) }}" method="POST" class="tooltip tooltip-left" data-tip="Delete {{ $offshore->name }}" data-confirm="{{ $deleteConfirmation }}" data-confirm-title="Delete offshore?" data-confirm-label="Delete offshore" data-confirm-tone="error">
                                            @csrf
                                            @method('DELETE')
                                            <button id="offshore-{{ $offshore->id }}-delete" type="submit" class="btn btn-outline btn-error btn-sm" aria-label="Delete {{ $offshore->name }}">
                                                <x-icon name="o-trash" class="size-4" />
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $canManageOffshores ? 7 : 6 }}" class="py-6 text-center nexus-text-muted">
                                No offshores configured yet.
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </x-card>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,20rem)_minmax(0,1fr)]">
            <x-card title="Main Bank Snapshot">
                <x-slot:menu>
                    @if($canManageOffshores)
                        <form action="{{ route('admin.offshores.main-bank.refresh') }}" method="POST">
                            @csrf
                            <button id="offshore-main-bank-balances-refresh" type="submit" class="btn btn-outline btn-sm">
                                <x-icon name="o-arrow-path" class="size-4" />
                                Refresh
                            </button>
                        </form>
                    @endif
                </x-slot:menu>

                @php
                    $visibleMainBalances = collect($mainBankSnapshot['balances'] ?? [])
                        ->filter(fn ($amount) => $amount !== null);
                @endphp

                @if($visibleMainBalances->isNotEmpty())
                    <div class="text-sm nexus-text-muted">
                        Cached {{ $mainBankCachedAt ? $mainBankCachedAt->diffForHumans() : 'recently' }}
                    </div>
                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach($visibleMainBalances as $resource => $amount)
                            <span class="badge badge-outline whitespace-normal break-words py-3 text-left">
                                {{ $resource }}:
                                {{ $resource === 'money' ? '$' . number_format($amount, 2) : number_format($amount, 2) }}
                            </span>
                        @endforeach
                    </div>
                @else
                    <p class="text-sm nexus-text-muted">No cached main bank data yet.</p>
                @endif

                @if($canManageOffshores)
                    <div class="mt-4 rounded-box border border-base-300 bg-base-200/50 p-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h3 class="font-semibold">Main Bank Credentials</h3>
                                <p class="text-sm nexus-text-muted">Database overrides are encrypted. Leave either field blank to keep its current value.</p>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <span class="badge {{ $mainBankCredentialStatus['api_key_configured'] ? 'badge-success' : 'badge-error' }}">
                                    API {{ $mainBankCredentialStatus['api_key_configured'] ? 'configured' : 'missing' }}
                                </span>
                                <span class="badge {{ $mainBankCredentialStatus['mutation_key_configured'] ? 'badge-success' : 'badge-error' }}">
                                    Mutation {{ $mainBankCredentialStatus['mutation_key_configured'] ? 'configured' : 'missing' }}
                                </span>
                            </div>
                        </div>

                        <form action="{{ route('admin.offshores.main-bank.credentials.update') }}" method="POST" autocomplete="off" class="mt-4 space-y-4">
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="modal_context" value="main-bank-credentials">

                            <label class="block space-y-2" for="offshore-main-bank-api-key">
                                <span class="text-sm font-medium">API Key</span>
                                <input
                                    id="offshore-main-bank-api-key"
                                    type="password"
                                    class="input w-full"
                                    name="api_key"
                                    minlength="20"
                                    maxlength="20"
                                    placeholder="Leave blank to keep current key"
                                    autocomplete="new-password"
                                    aria-describedby="offshore-main-bank-api-key-help{{ $mainBankCredentialContext && $errors->has('api_key') ? ' offshore-main-bank-api-key-error' : '' }}"
                                    aria-invalid="{{ $mainBankCredentialContext && $errors->has('api_key') ? 'true' : 'false' }}"
                                >
                                <span id="offshore-main-bank-api-key-help" class="text-xs nexus-text-muted">
                                    Current source: {{ $mainBankCredentialStatus['api_key_source'] === 'database' ? 'encrypted Nexus storage' : ($mainBankCredentialStatus['api_key_source'] === 'environment' ? 'deployment environment' : 'not configured') }}.
                                </span>
                                @if($mainBankCredentialContext && $errors->has('api_key'))
                                    <span id="offshore-main-bank-api-key-error" class="text-xs text-error">{{ $errors->first('api_key') }}</span>
                                @endif
                            </label>

                            <label class="block space-y-2" for="offshore-main-bank-mutation-key">
                                <span class="text-sm font-medium">Mutation Key</span>
                                <input
                                    id="offshore-main-bank-mutation-key"
                                    type="password"
                                    class="input w-full"
                                    name="mutation_key"
                                    maxlength="255"
                                    placeholder="Leave blank to keep current key"
                                    autocomplete="new-password"
                                    aria-describedby="offshore-main-bank-mutation-key-help{{ $mainBankCredentialContext && $errors->has('mutation_key') ? ' offshore-main-bank-mutation-key-error' : '' }}"
                                    aria-invalid="{{ $mainBankCredentialContext && $errors->has('mutation_key') ? 'true' : 'false' }}"
                                >
                                <span id="offshore-main-bank-mutation-key-help" class="text-xs nexus-text-muted">
                                    Current source: {{ $mainBankCredentialStatus['mutation_key_source'] === 'database' ? 'encrypted Nexus storage' : ($mainBankCredentialStatus['mutation_key_source'] === 'environment' ? 'deployment environment' : 'not configured') }}.
                                </span>
                                @if($mainBankCredentialContext && $errors->has('mutation_key'))
                                    <span id="offshore-main-bank-mutation-key-error" class="text-xs text-error">{{ $errors->first('mutation_key') }}</span>
                                @endif
                            </label>

                            <button id="offshore-main-bank-credentials-submit" type="submit" class="btn btn-primary w-full">
                                <x-icon name="o-key" class="size-4" />
                                Save Credentials
                            </button>
                        </form>
                    </div>

                    <div class="mt-4 rounded-box border border-base-300 bg-base-200/50 p-4">
                        <p class="mb-3 text-sm nexus-text-muted">
                            Bridge funds between the main bank and offshores. Transfers are executed instantly using the configured API keys.
                        </p>
                        <button id="offshore-manual-transfer-modal-open" class="btn btn-outline w-full" type="button" data-dialog-open="manualTransferModal">
                            <x-icon name="o-banknotes" class="size-4" />
                            Start Transfer
                        </button>
                    </div>
                @endif
            </x-card>

            <x-card title="Recent Manual Transfers" :subtitle="'Last ' . $transfers->count() . ' records'">
                <div class="overflow-x-auto rounded-box border border-base-300">
                    <table class="table table-zebra" data-sortable="false">
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
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span>{{ $transfer->source_type === \App\Models\OffshoreTransfer::TYPE_MAIN ? 'Main Bank' : ($transfer->sourceOffshore?->name ?? 'Offshore') }}</span>
                                        <x-icon name="o-arrow-right" class="size-4 nexus-text-muted" />
                                        <span>{{ $transfer->destination_type === \App\Models\OffshoreTransfer::TYPE_MAIN ? 'Main Bank' : ($transfer->destinationOffshore?->name ?? 'Offshore') }}</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="flex flex-wrap gap-2">
                                        @foreach($transfer->payload as $resource => $amount)
                                            <span class="badge badge-outline whitespace-normal break-words py-3 text-left">
                                                {{ $resource }}:
                                                {{ $resource === 'money' ? '$' . number_format($amount, 2) : number_format($amount, 2) }}
                                            </span>
                                        @endforeach
                                    </div>
                                    @if($transfer->message)
                                        <div class="mt-2 text-sm nexus-text-muted">{{ $transfer->message }}</div>
                                    @endif
                                </td>
                                <td>
                                    @switch($transfer->status)
                                        @case(\App\Models\OffshoreTransfer::STATUS_COMPLETED)
                                            <span class="badge badge-success">Completed</span>
                                            @break
                                        @case(\App\Models\OffshoreTransfer::STATUS_FAILED)
                                            <span class="badge badge-error">Failed</span>
                                            @break
                                        @default
                                            <span class="badge badge-ghost">Pending</span>
                                    @endswitch
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="py-6 text-center nexus-text-muted">No transfers recorded yet.</td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </x-card>
        </div>
    </div>

    @if($canManageOffshores)
        <dialog id="createOffshoreModal" class="modal" aria-label="Create offshore">
            <div class="modal-box max-w-4xl">
                <form action="{{ route('admin.offshores.store') }}" method="POST" autocomplete="off" class="space-y-6">
                    @csrf
                    <input type="hidden" name="modal_context" value="create">

                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h3 class="text-lg font-semibold">Add Offshore</h3>
                            <p class="text-sm nexus-text-muted">Create an offshore entry and define any transfer guardrails.</p>
                        </div>
                        <button id="offshore-create-new-modal-close" type="button" class="btn btn-circle btn-ghost btn-sm" data-dialog-close="createOffshoreModal" aria-label="Close offshore creation dialog">✕</button>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <label class="block space-y-2" for="offshore-create-new-name">
                            <span class="text-sm font-medium">Name</span>
                            <input id="offshore-create-new-name" type="text" class="input w-full" name="name" value="{{ $modalContext === 'create' ? old('name') : '' }}" required
                                   aria-invalid="{{ $modalContext === 'create' && $errors->has('name') ? 'true' : 'false' }}"
                                   @if($modalContext === 'create' && $errors->has('name')) aria-describedby="offshore-create-new-name-error" @endif>
                            @if($modalContext === 'create' && $errors->has('name'))
                                <span id="offshore-create-new-name-error" class="text-xs text-error">{{ $errors->first('name') }}</span>
                            @endif
                        </label>
                        <label class="block space-y-2" for="offshore-create-new-alliance-id">
                            <span class="text-sm font-medium">Alliance ID</span>
                            <input id="offshore-create-new-alliance-id" type="number" class="input w-full" name="alliance_id" value="{{ $modalContext === 'create' ? old('alliance_id') : '' }}" min="1" required
                                   aria-invalid="{{ $modalContext === 'create' && $errors->has('alliance_id') ? 'true' : 'false' }}"
                                   @if($modalContext === 'create' && $errors->has('alliance_id')) aria-describedby="offshore-create-new-alliance-id-error" @endif>
                            @if($modalContext === 'create' && $errors->has('alliance_id'))
                                <span id="offshore-create-new-alliance-id-error" class="text-xs text-error">{{ $errors->first('alliance_id') }}</span>
                            @endif
                        </label>
                        <label class="block space-y-2" for="offshore-create-new-api-key">
                            <span class="text-sm font-medium">API Key</span>
                            <input id="offshore-create-new-api-key" type="text" class="input w-full" name="api_key" value="{{ $modalContext === 'create' ? old('api_key') : '' }}" minlength="20" maxlength="20" required
                                   aria-describedby="offshore-create-new-api-key-help{{ $modalContext === 'create' && $errors->has('api_key') ? ' offshore-create-new-api-key-error' : '' }}"
                                   aria-invalid="{{ $modalContext === 'create' && $errors->has('api_key') ? 'true' : 'false' }}">
                            <span id="offshore-create-new-api-key-help" class="text-xs nexus-text-muted">Stored encrypted. Paste the 20-character offshore bot API key.</span>
                            @if($modalContext === 'create' && $errors->has('api_key'))
                                <span id="offshore-create-new-api-key-error" class="text-xs text-error">{{ $errors->first('api_key') }}</span>
                            @endif
                        </label>
                        <label class="block space-y-2" for="offshore-create-new-mutation-key">
                            <span class="text-sm font-medium">Mutation Key</span>
                            <input id="offshore-create-new-mutation-key" type="text" class="input w-full" name="mutation_key" value="{{ $modalContext === 'create' ? old('mutation_key') : '' }}" required
                                   aria-invalid="{{ $modalContext === 'create' && $errors->has('mutation_key') ? 'true' : 'false' }}"
                                   @if($modalContext === 'create' && $errors->has('mutation_key')) aria-describedby="offshore-create-new-mutation-key-error" @endif>
                            @if($modalContext === 'create' && $errors->has('mutation_key'))
                                <span id="offshore-create-new-mutation-key-error" class="text-xs text-error">{{ $errors->first('mutation_key') }}</span>
                            @endif
                        </label>
                        <label class="block space-y-2" for="offshore-create-new-priority">
                            <span class="text-sm font-medium">Priority</span>
                            <input id="offshore-create-new-priority" type="number" class="input w-full" name="priority" value="{{ $modalContext === 'create' ? old('priority', 0) : 0 }}" min="0"
                                   aria-invalid="{{ $modalContext === 'create' && $errors->has('priority') ? 'true' : 'false' }}"
                                   @if($modalContext === 'create' && $errors->has('priority')) aria-describedby="offshore-create-new-priority-error" @endif>
                            @if($modalContext === 'create' && $errors->has('priority'))
                                <span id="offshore-create-new-priority-error" class="text-xs text-error">{{ $errors->first('priority') }}</span>
                            @endif
                        </label>
                        <label class="block space-y-2" for="offshore-create-new-enabled">
                            <span class="text-sm font-medium">Enabled</span>
                            @php $createEnabled = $modalContext === 'create' ? (int) old('enabled', 1) : 1; @endphp
                            <select id="offshore-create-new-enabled" class="select w-full" name="enabled"
                                    aria-invalid="{{ $modalContext === 'create' && $errors->has('enabled') ? 'true' : 'false' }}"
                                    @if($modalContext === 'create' && $errors->has('enabled')) aria-describedby="offshore-create-new-enabled-error" @endif>
                                <option value="1" {{ $createEnabled === 1 ? 'selected' : '' }}>Yes</option>
                                <option value="0" {{ $createEnabled === 0 ? 'selected' : '' }}>No</option>
                            </select>
                            @if($modalContext === 'create' && $errors->has('enabled'))
                                <span id="offshore-create-new-enabled-error" class="text-xs text-error">{{ $errors->first('enabled') }}</span>
                            @endif
                        </label>
                    </div>

                    <fieldset class="space-y-3">
                        <legend class="font-semibold">Guardrails</legend>
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <p id="offshore-create-new-guardrails-help" class="text-sm nexus-text-muted">Prevent automated withdrawals from dropping a resource below a minimum.</p>
                            <button id="offshore-create-new-guardrail-add" class="btn btn-outline btn-sm" type="button" data-action="add-guardrail" data-target="#create-guardrail-container">
                                <x-icon name="o-plus-circle" class="size-4" />
                                Add Guardrail
                            </button>
                        </div>
                        @php $createGuardrails = $modalContext === 'create' ? old('guardrails', []) : []; @endphp
                        <input type="hidden" name="guardrails" value="">
                        <div id="create-guardrail-container" class="guardrail-container space-y-3" data-next-index="{{ count($createGuardrails) }}" data-control-prefix="offshore-create-new">
                            @foreach($createGuardrails as $index => $guardrail)
                                @php
                                    $resourceValue = $guardrail['resource'] ?? '';
                                    $amountValue = $guardrail['minimum_amount'] ?? 0;
                                    $resourceErrorKey = 'guardrails.'.$index.'.resource';
                                    $amountErrorKey = 'guardrails.'.$index.'.minimum_amount';
                                @endphp
                                <div class="guardrail-row grid gap-3 rounded-box border border-base-300 p-3 md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] md:items-end">
                                    <label class="block space-y-2" for="offshore-create-new-guardrail-{{ $index }}-resource">
                                        <span class="text-sm font-medium">Resource</span>
                                        <select id="offshore-create-new-guardrail-{{ $index }}-resource" class="select w-full" name="guardrails[{{ $index }}][resource]" required
                                                aria-invalid="{{ $errors->has($resourceErrorKey) ? 'true' : 'false' }}"
                                                @if($errors->has($resourceErrorKey)) aria-describedby="offshore-create-new-guardrail-{{ $index }}-resource-error" @endif>
                                            @foreach($guardrailResources as $resource)
                                                <option value="{{ $resource }}" {{ $resourceValue === $resource ? 'selected' : '' }}>{{ ucfirst($resource) }}</option>
                                            @endforeach
                                        </select>
                                        @if($errors->has($resourceErrorKey))
                                            <span id="offshore-create-new-guardrail-{{ $index }}-resource-error" class="text-xs text-error">{{ $errors->first($resourceErrorKey) }}</span>
                                        @endif
                                    </label>
                                    <label class="block space-y-2" for="offshore-create-new-guardrail-{{ $index }}-minimum-amount">
                                        <span class="text-sm font-medium">Minimum Amount</span>
                                        <input id="offshore-create-new-guardrail-{{ $index }}-minimum-amount" type="number" step="0.01" min="0" class="input w-full" name="guardrails[{{ $index }}][minimum_amount]" value="{{ $amountValue }}" required
                                               aria-invalid="{{ $errors->has($amountErrorKey) ? 'true' : 'false' }}"
                                               @if($errors->has($amountErrorKey)) aria-describedby="offshore-create-new-guardrail-{{ $index }}-minimum-amount-error" @endif>
                                        @if($errors->has($amountErrorKey))
                                            <span id="offshore-create-new-guardrail-{{ $index }}-minimum-amount-error" class="text-xs text-error">{{ $errors->first($amountErrorKey) }}</span>
                                        @endif
                                    </label>
                                    <button id="offshore-create-new-guardrail-{{ $index }}-remove" type="button" class="btn btn-outline btn-error btn-sm md:self-end" data-action="remove-guardrail" aria-label="Remove guardrail {{ $loop->iteration }}">
                                        <x-icon name="o-trash" class="size-4" />
                                    </button>
                                </div>
                            @endforeach
                        </div>
                    </fieldset>

                    <div class="flex justify-end gap-2">
                        <button id="offshore-create-new-cancel" type="button" class="btn btn-ghost" data-dialog-close="createOffshoreModal">Cancel</button>
                        <button id="offshore-create-new-submit" type="submit" class="btn btn-primary">Save Offshore</button>
                    </div>
                </form>
            </div>
            <form method="dialog" class="modal-backdrop"><button id="offshore-create-new-backdrop-close" aria-label="Close offshore creation dialog">close</button></form>
        </dialog>

        @foreach($offshores as $offshore)
            <dialog id="editOffshoreModal-{{ $offshore->id }}" class="modal" aria-label="Edit offshore {{ $offshore->name }}">
                <div class="modal-box max-w-4xl">
                    <form action="{{ route('admin.offshores.update', $offshore) }}" method="POST" autocomplete="off" class="space-y-6">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="modal_context" value="edit-{{ $offshore->id }}">

                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <h3 class="text-lg font-semibold">Edit {{ $offshore->name }}</h3>
                                <p class="text-sm nexus-text-muted">Update credentials, ordering, and resource guardrails.</p>
                            </div>
                            <button id="offshore-edit-{{ $offshore->id }}-modal-close" type="button" class="btn btn-circle btn-ghost btn-sm" data-dialog-close="editOffshoreModal-{{ $offshore->id }}" aria-label="Close offshore editing dialog for {{ $offshore->name }}">✕</button>
                        </div>

                        @php $editContext = $modalContext === 'edit-' . $offshore->id; @endphp
                        <div class="grid gap-4 md:grid-cols-2">
                            <label class="block space-y-2" for="offshore-edit-{{ $offshore->id }}-name">
                                <span class="text-sm font-medium">Name</span>
                                <input id="offshore-edit-{{ $offshore->id }}-name" type="text" class="input w-full" name="name" value="{{ $editContext ? old('name', $offshore->name) : $offshore->name }}"
                                       aria-invalid="{{ $editContext && $errors->has('name') ? 'true' : 'false' }}"
                                       @if($editContext && $errors->has('name')) aria-describedby="offshore-edit-{{ $offshore->id }}-name-error" @endif>
                                @if($editContext && $errors->has('name'))
                                    <span id="offshore-edit-{{ $offshore->id }}-name-error" class="text-xs text-error">{{ $errors->first('name') }}</span>
                                @endif
                            </label>
                            <label class="block space-y-2" for="offshore-edit-{{ $offshore->id }}-alliance-id">
                                <span class="text-sm font-medium">Alliance ID</span>
                                <input id="offshore-edit-{{ $offshore->id }}-alliance-id" type="number" class="input w-full" name="alliance_id" value="{{ $editContext ? old('alliance_id', $offshore->alliance_id) : $offshore->alliance_id }}" min="1"
                                       aria-invalid="{{ $editContext && $errors->has('alliance_id') ? 'true' : 'false' }}"
                                       @if($editContext && $errors->has('alliance_id')) aria-describedby="offshore-edit-{{ $offshore->id }}-alliance-id-error" @endif>
                                @if($editContext && $errors->has('alliance_id'))
                                    <span id="offshore-edit-{{ $offshore->id }}-alliance-id-error" class="text-xs text-error">{{ $errors->first('alliance_id') }}</span>
                                @endif
                            </label>
                            <label class="block space-y-2" for="offshore-edit-{{ $offshore->id }}-api-key">
                                <span class="text-sm font-medium">API Key</span>
                                <input id="offshore-edit-{{ $offshore->id }}-api-key" type="text" class="input w-full" name="api_key" minlength="20" maxlength="20" placeholder="Leave blank to keep current key"
                                       aria-describedby="offshore-edit-{{ $offshore->id }}-api-key-help{{ $editContext && $errors->has('api_key') ? ' offshore-edit-'.$offshore->id.'-api-key-error' : '' }}"
                                       aria-invalid="{{ $editContext && $errors->has('api_key') ? 'true' : 'false' }}">
                                <span id="offshore-edit-{{ $offshore->id }}-api-key-help" class="sr-only">Leave blank to keep the current key.</span>
                                @if($editContext && $errors->has('api_key'))
                                    <span id="offshore-edit-{{ $offshore->id }}-api-key-error" class="text-xs text-error">{{ $errors->first('api_key') }}</span>
                                @endif
                            </label>
                            <label class="block space-y-2" for="offshore-edit-{{ $offshore->id }}-mutation-key">
                                <span class="text-sm font-medium">Mutation Key</span>
                                <input id="offshore-edit-{{ $offshore->id }}-mutation-key" type="text" class="input w-full" name="mutation_key" placeholder="Leave blank to keep current key"
                                       aria-describedby="offshore-edit-{{ $offshore->id }}-mutation-key-help{{ $editContext && $errors->has('mutation_key') ? ' offshore-edit-'.$offshore->id.'-mutation-key-error' : '' }}"
                                       aria-invalid="{{ $editContext && $errors->has('mutation_key') ? 'true' : 'false' }}">
                                <span id="offshore-edit-{{ $offshore->id }}-mutation-key-help" class="sr-only">Leave blank to keep the current key.</span>
                                @if($editContext && $errors->has('mutation_key'))
                                    <span id="offshore-edit-{{ $offshore->id }}-mutation-key-error" class="text-xs text-error">{{ $errors->first('mutation_key') }}</span>
                                @endif
                            </label>
                            <label class="block space-y-2" for="offshore-edit-{{ $offshore->id }}-priority">
                                <span class="text-sm font-medium">Priority</span>
                                <input id="offshore-edit-{{ $offshore->id }}-priority" type="number" class="input w-full" name="priority" value="{{ $editContext ? old('priority', $offshore->priority) : $offshore->priority }}" min="0"
                                       aria-invalid="{{ $editContext && $errors->has('priority') ? 'true' : 'false' }}"
                                       @if($editContext && $errors->has('priority')) aria-describedby="offshore-edit-{{ $offshore->id }}-priority-error" @endif>
                                @if($editContext && $errors->has('priority'))
                                    <span id="offshore-edit-{{ $offshore->id }}-priority-error" class="text-xs text-error">{{ $errors->first('priority') }}</span>
                                @endif
                            </label>
                            <label class="block space-y-2" for="offshore-edit-{{ $offshore->id }}-enabled">
                                <span class="text-sm font-medium">Enabled</span>
                                @php $editEnabled = $editContext ? (int) old('enabled', $offshore->enabled ? 1 : 0) : ($offshore->enabled ? 1 : 0); @endphp
                                <select id="offshore-edit-{{ $offshore->id }}-enabled" class="select w-full" name="enabled"
                                        aria-invalid="{{ $editContext && $errors->has('enabled') ? 'true' : 'false' }}"
                                        @if($editContext && $errors->has('enabled')) aria-describedby="offshore-edit-{{ $offshore->id }}-enabled-error" @endif>
                                    <option value="1" {{ $editEnabled === 1 ? 'selected' : '' }}>Yes</option>
                                    <option value="0" {{ $editEnabled === 0 ? 'selected' : '' }}>No</option>
                                </select>
                                @if($editContext && $errors->has('enabled'))
                                    <span id="offshore-edit-{{ $offshore->id }}-enabled-error" class="text-xs text-error">{{ $errors->first('enabled') }}</span>
                                @endif
                            </label>
                        </div>

                        <fieldset class="space-y-3">
                            <legend class="font-semibold">Guardrails</legend>
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <p id="offshore-edit-{{ $offshore->id }}-guardrails-help" class="text-sm nexus-text-muted">Leave guardrails empty to allow the service to manage resources freely.</p>
                                <button id="offshore-edit-{{ $offshore->id }}-guardrail-add" class="btn btn-outline btn-sm" type="button" data-action="add-guardrail" data-target="#edit-guardrail-container-{{ $offshore->id }}">
                                    <x-icon name="o-plus-circle" class="size-4" />
                                    Add Guardrail
                                </button>
                            </div>
                            @php
                                $editGuardrails = $editContext
                                    ? old('guardrails', [])
                                    : $offshore->guardrails->map(fn ($guardrail) => [
                                        'resource' => $guardrail->resource,
                                        'minimum_amount' => $guardrail->minimum_amount,
                                    ])->all();
                            @endphp
                            <input type="hidden" name="guardrails" value="">
                            <div id="edit-guardrail-container-{{ $offshore->id }}" class="guardrail-container space-y-3" data-next-index="{{ count($editGuardrails) }}" data-control-prefix="offshore-edit-{{ $offshore->id }}">
                                @foreach($editGuardrails as $index => $guardrail)
                                    @php
                                        $resourceValue = $guardrail['resource'] ?? '';
                                        $amountValue = $guardrail['minimum_amount'] ?? 0;
                                        $resourceErrorKey = 'guardrails.'.$index.'.resource';
                                        $amountErrorKey = 'guardrails.'.$index.'.minimum_amount';
                                    @endphp
                                    <div class="guardrail-row grid gap-3 rounded-box border border-base-300 p-3 md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] md:items-end">
                                        <label class="block space-y-2" for="offshore-edit-{{ $offshore->id }}-guardrail-{{ $index }}-resource">
                                            <span class="text-sm font-medium">Resource</span>
                                            <select id="offshore-edit-{{ $offshore->id }}-guardrail-{{ $index }}-resource" class="select w-full" name="guardrails[{{ $index }}][resource]" required
                                                    aria-invalid="{{ $editContext && $errors->has($resourceErrorKey) ? 'true' : 'false' }}"
                                                    @if($editContext && $errors->has($resourceErrorKey)) aria-describedby="offshore-edit-{{ $offshore->id }}-guardrail-{{ $index }}-resource-error" @endif>
                                                @foreach($guardrailResources as $resource)
                                                    <option value="{{ $resource }}" {{ $resourceValue === $resource ? 'selected' : '' }}>{{ ucfirst($resource) }}</option>
                                                @endforeach
                                            </select>
                                            @if($editContext && $errors->has($resourceErrorKey))
                                                <span id="offshore-edit-{{ $offshore->id }}-guardrail-{{ $index }}-resource-error" class="text-xs text-error">{{ $errors->first($resourceErrorKey) }}</span>
                                            @endif
                                        </label>
                                        <label class="block space-y-2" for="offshore-edit-{{ $offshore->id }}-guardrail-{{ $index }}-minimum-amount">
                                            <span class="text-sm font-medium">Minimum Amount</span>
                                            <input id="offshore-edit-{{ $offshore->id }}-guardrail-{{ $index }}-minimum-amount" type="number" step="0.01" min="0" class="input w-full" name="guardrails[{{ $index }}][minimum_amount]" value="{{ $amountValue }}" required
                                                   aria-invalid="{{ $editContext && $errors->has($amountErrorKey) ? 'true' : 'false' }}"
                                                   @if($editContext && $errors->has($amountErrorKey)) aria-describedby="offshore-edit-{{ $offshore->id }}-guardrail-{{ $index }}-minimum-amount-error" @endif>
                                            @if($editContext && $errors->has($amountErrorKey))
                                                <span id="offshore-edit-{{ $offshore->id }}-guardrail-{{ $index }}-minimum-amount-error" class="text-xs text-error">{{ $errors->first($amountErrorKey) }}</span>
                                            @endif
                                        </label>
                                        <button id="offshore-edit-{{ $offshore->id }}-guardrail-{{ $index }}-remove" type="button" class="btn btn-outline btn-error btn-sm md:self-end" data-action="remove-guardrail" aria-label="Remove guardrail {{ $loop->iteration }} from {{ $offshore->name }}">
                                            <x-icon name="o-trash" class="size-4" />
                                        </button>
                                    </div>
                                @endforeach
                            </div>
                        </fieldset>

                        <div class="flex justify-end gap-2">
                            <button id="offshore-edit-{{ $offshore->id }}-cancel" type="button" class="btn btn-ghost" data-dialog-close="editOffshoreModal-{{ $offshore->id }}">Cancel</button>
                            <button id="offshore-edit-{{ $offshore->id }}-submit" type="submit" class="btn btn-primary">Save Changes</button>
                        </div>
                    </form>
                </div>
                <form method="dialog" class="modal-backdrop"><button id="offshore-edit-{{ $offshore->id }}-backdrop-close" aria-label="Close offshore editing dialog for {{ $offshore->name }}">close</button></form>
            </dialog>
        @endforeach

        <dialog id="manualTransferModal" class="modal" aria-label="Transfer offshore funds">
            <div class="modal-box max-w-4xl">
                <form action="{{ route('admin.offshores.transfer') }}" method="POST" autocomplete="off" class="space-y-6">
                    @csrf

                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h3 class="text-lg font-semibold">Manual Offshore Transfer</h3>
                            <p class="text-sm nexus-text-muted">Move funds between the main bank and one offshore. For offshore-to-offshore moves, complete and verify two separate transfers through the main bank.</p>
                        </div>
                        <button id="offshore-manual-transfer-modal-close" type="button" class="btn btn-circle btn-ghost btn-sm" data-dialog-close="manualTransferModal" aria-label="Close transfer dialog">✕</button>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <label class="block space-y-2" for="offshore-manual-transfer-source-type">
                            <span class="text-sm font-medium">Source</span>
                            <select class="select w-full" name="source_type" id="offshore-manual-transfer-source-type" required
                                    aria-invalid="{{ $errors->has('source_type') || $errors->has('source_offshore_id') ? 'true' : 'false' }}"
                                    @if($errors->has('source_type') || $errors->has('source_offshore_id')) aria-describedby="offshore-manual-transfer-source-error" @endif>
                                <option value="{{ \App\Models\OffshoreTransfer::TYPE_MAIN }}">Main Bank</option>
                                @foreach($offshores as $offshore)
                                    <option value="{{ \App\Models\OffshoreTransfer::TYPE_OFFSHORE }}" data-offshore-id="{{ $offshore->id }}">
                                        {{ $offshore->name }}
                                    </option>
                                @endforeach
                            </select>
                            <input type="hidden" name="source_offshore_id" id="offshore-manual-transfer-source-id">
                            @if($errors->has('source_type') || $errors->has('source_offshore_id'))
                                <span id="offshore-manual-transfer-source-error" class="text-xs text-error">{{ $errors->first('source_type') ?: $errors->first('source_offshore_id') }}</span>
                            @endif
                        </label>
                        <label class="block space-y-2" for="offshore-manual-transfer-destination-type">
                            <span class="text-sm font-medium">Destination</span>
                            <select class="select w-full" name="destination_type" id="offshore-manual-transfer-destination-type" required
                                    aria-invalid="{{ $errors->has('destination_type') || $errors->has('destination_offshore_id') ? 'true' : 'false' }}"
                                    @if($errors->has('destination_type') || $errors->has('destination_offshore_id')) aria-describedby="offshore-manual-transfer-destination-error" @endif>
                                <option value="{{ \App\Models\OffshoreTransfer::TYPE_MAIN }}">Main Bank</option>
                                @foreach($offshores as $offshore)
                                    <option value="{{ \App\Models\OffshoreTransfer::TYPE_OFFSHORE }}" data-offshore-id="{{ $offshore->id }}">
                                        {{ $offshore->name }}
                                    </option>
                                @endforeach
                            </select>
                            <input type="hidden" name="destination_offshore_id" id="offshore-manual-transfer-destination-id">
                            @if($errors->has('destination_type') || $errors->has('destination_offshore_id'))
                                <span id="offshore-manual-transfer-destination-error" class="text-xs text-error">{{ $errors->first('destination_type') ?: $errors->first('destination_offshore_id') }}</span>
                            @endif
                        </label>
                    </div>

                    <label class="block space-y-2" for="offshore-manual-transfer-note">
                        <span class="text-sm font-medium">Operator Note <span class="nexus-text-muted">(optional)</span></span>
                        <input id="offshore-manual-transfer-note" type="text" class="input w-full" name="note" placeholder="Visible in bank records and audit logs"
                               aria-invalid="{{ $errors->has('note') ? 'true' : 'false' }}"
                               @if($errors->has('note')) aria-describedby="offshore-manual-transfer-note-error" @endif>
                        @error('note')
                            <span id="offshore-manual-transfer-note-error" class="text-xs text-error">{{ $message }}</span>
                        @enderror
                    </label>

                    <fieldset class="space-y-3" aria-describedby="offshore-manual-transfer-resources-help{{ $errors->has('resources') ? ' offshore-manual-transfer-resources-error' : '' }}">
                        <legend class="sr-only">Resource amounts</legend>
                        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                            @foreach($resources as $resource)
                            <label class="block space-y-2" for="offshore-manual-transfer-resource-{{ $resource }}">
                                <span class="text-sm font-medium capitalize">{{ $resource }}</span>
                                <div class="join w-full">
                                    @if($resource === 'money')
                                        <span class="join-item flex items-center border border-base-300 bg-base-200 px-3 text-sm">$</span>
                                    @endif
                                    <input
                                        id="offshore-manual-transfer-resource-{{ $resource }}"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        class="input join-item w-full"
                                        name="resources[{{ $resource }}]"
                                        placeholder="0.00"
                                        aria-invalid="{{ $errors->has('resources.'.$resource) ? 'true' : 'false' }}"
                                        @if($errors->has('resources.'.$resource)) aria-describedby="offshore-manual-transfer-resource-{{ $resource }}-error" @endif
                                    >
                                </div>
                                @error('resources.'.$resource)
                                    <span id="offshore-manual-transfer-resource-{{ $resource }}-error" class="text-xs text-error">{{ $message }}</span>
                                @enderror
                            </label>
                            @endforeach
                        </div>

                        <p id="offshore-manual-transfer-resources-help" class="text-sm nexus-text-muted">Only resources with amounts greater than zero will be transferred.</p>
                        @error('resources')
                            <p id="offshore-manual-transfer-resources-error" class="text-sm text-error">{{ $message }}</p>
                        @enderror
                    </fieldset>

                    <div class="flex justify-end gap-2">
                        <button id="offshore-manual-transfer-cancel" type="button" class="btn btn-ghost" data-dialog-close="manualTransferModal">Cancel</button>
                        <button id="offshore-manual-transfer-submit" type="submit" class="btn btn-primary">Execute Transfer</button>
                    </div>
                </form>
            </div>
            <form method="dialog" class="modal-backdrop"><button id="offshore-manual-transfer-backdrop-close" aria-label="Close transfer dialog">close</button></form>
        </dialog>
    @endif

    <template id="guardrail-row-template">
        <div class="guardrail-row grid gap-3 rounded-box border border-base-300 p-3 md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] md:items-end">
            <label class="block space-y-2" for="__CONTEXT__-guardrail-__INDEX__-resource">
                <span class="text-sm font-medium">Resource</span>
                <select id="__CONTEXT__-guardrail-__INDEX__-resource" class="select w-full" name="guardrails[__INDEX__][resource]" aria-invalid="false" required>
                    @foreach($guardrailResources as $resource)
                        <option value="{{ $resource }}">{{ ucfirst($resource) }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block space-y-2" for="__CONTEXT__-guardrail-__INDEX__-minimum-amount">
                <span class="text-sm font-medium">Minimum Amount</span>
                <input id="__CONTEXT__-guardrail-__INDEX__-minimum-amount" type="number" step="0.01" min="0" class="input w-full" name="guardrails[__INDEX__][minimum_amount]" aria-invalid="false" required>
            </label>
            <button id="__CONTEXT__-guardrail-__INDEX__-remove" type="button" class="btn btn-outline btn-error btn-sm md:self-end" data-action="remove-guardrail" aria-label="Remove guardrail __INDEX__">
                <x-icon name="o-trash" class="size-4" />
            </button>
        </div>
    </template>
@endsection

@push('scripts')
    <script>
        function initOffshoreAdminPage() {
            const guardrailTemplate = document.getElementById('guardrail-row-template');

            document.querySelectorAll('[data-dialog-open]').forEach((button) => {
                if (button.dataset.bound === 'true') {
                    return;
                }

                button.dataset.bound = 'true';
                button.addEventListener('click', () => {
                    const dialog = document.getElementById(button.dataset.dialogOpen);
                    dialog?.showModal();
                });
            });

            document.querySelectorAll('[data-dialog-close]').forEach((button) => {
                if (button.dataset.bound === 'true') {
                    return;
                }

                button.dataset.bound = 'true';
                button.addEventListener('click', () => {
                    const dialog = document.getElementById(button.dataset.dialogClose);
                    dialog?.close();
                });
            });

            document.querySelectorAll('[data-action="add-guardrail"]').forEach((button) => {
                if (button.dataset.bound === 'true') {
                    return;
                }

                button.dataset.bound = 'true';
                button.addEventListener('click', () => {
                    const targetSelector = button.dataset.target;
                    const container = targetSelector ? document.querySelector(targetSelector) : null;

                    if (! container || ! guardrailTemplate) {
                        return;
                    }

                    const nextIndex = parseInt(container.dataset.nextIndex ?? container.querySelectorAll('.guardrail-row').length, 10);
                    const controlPrefix = container.dataset.controlPrefix ?? 'offshore-unknown';
                    const content = guardrailTemplate.innerHTML
                        .replace(/__CONTEXT__/g, controlPrefix)
                        .replace(/__INDEX__/g, String(nextIndex));
                    container.insertAdjacentHTML('beforeend', content);
                    container.dataset.nextIndex = String(nextIndex + 1);
                });
            });

            document.addEventListener('click', (event) => {
                const removeButton = event.target.closest('[data-action="remove-guardrail"]');

                if (removeButton) {
                    removeButton.closest('.guardrail-row')?.remove();
                }
            });

            const sourceTypeSelect = document.getElementById('offshore-manual-transfer-source-type');
            const destinationTypeSelect = document.getElementById('offshore-manual-transfer-destination-type');
            const sourceIdInput = document.getElementById('offshore-manual-transfer-source-id');
            const destinationIdInput = document.getElementById('offshore-manual-transfer-destination-id');

            const updateHiddenInput = (selectElement, hiddenInput) => {
                if (! selectElement || ! hiddenInput) {
                    return;
                }

                const selectedOption = selectElement.selectedOptions[0];
                hiddenInput.value = selectedOption?.dataset.offshoreId ?? '';
            };

            sourceTypeSelect?.addEventListener('change', () => updateHiddenInput(sourceTypeSelect, sourceIdInput));
            destinationTypeSelect?.addEventListener('change', () => updateHiddenInput(destinationTypeSelect, destinationIdInput));
            updateHiddenInput(sourceTypeSelect, sourceIdInput);
            updateHiddenInput(destinationTypeSelect, destinationIdInput);

            document.querySelectorAll('[data-action="open-transfer"]').forEach((button) => {
                if (button.dataset.bound === 'true') {
                    return;
                }

                button.dataset.bound = 'true';
                button.addEventListener('click', () => {
                    if (sourceTypeSelect && button.dataset.sourceType) {
                        sourceTypeSelect.value = button.dataset.sourceType;
                    }

                    if (destinationTypeSelect && button.dataset.destinationType) {
                        destinationTypeSelect.value = button.dataset.destinationType;
                    }

                    if (sourceIdInput) {
                        sourceIdInput.value = button.dataset.sourceId ?? '';
                    }

                    if (destinationIdInput) {
                        destinationIdInput.value = button.dataset.destinationId ?? '';
                    }

                    document.getElementById('manualTransferModal')?.showModal();
                });
            });

            @if($showCreateModal)
                document.getElementById('createOffshoreModal')?.showModal();
            @elseif($editOffshoreId)
                document.getElementById('editOffshoreModal-{{ $editOffshoreId }}')?.showModal();
            @endif
        }

        document.addEventListener('codex:page-ready', initOffshoreAdminPage);
        initOffshoreAdminPage();
    </script>
@endpush
