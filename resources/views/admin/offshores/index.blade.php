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
    <x-header title="Offshore Management" separator>
        <x-slot:subtitle>Monitor cached balances, adjust guardrails, and trigger manual transfers.</x-slot:subtitle>
        @if($canManageOffshores)
            <x-slot:actions>
                <button class="btn btn-primary btn-sm" type="button" data-dialog-open="createOffshoreModal">
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
                    <button class="btn btn-outline btn-sm" type="submit" form="offshore-priority-form">
                        <x-icon name="o-arrow-path" class="size-4" />
                        Save Priority Order
                    </button>
                @endif
            </x-slot:menu>

            <form id="offshore-priority-form" action="{{ route('admin.offshores.reorder') }}" method="POST" class="hidden">
                @csrf
            </form>

            <div class="overflow-x-auto rounded-box border border-base-300">
                <table class="table table-zebra">
                    <thead>
                    <tr>
                        <th>Priority</th>
                        <th>Name</th>
                        <th>Alliance</th>
                        <th>Status</th>
                        <th>Cached Balances</th>
                        <th>Guardrails</th>
                        @if($canManageOffshores)
                            <th class="text-right">Actions</th>
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
                                        type="number"
                                        name="order[{{ $offshore->id }}]"
                                        value="{{ old('order.' . $offshore->id, $offshore->priority) }}"
                                        class="input input-bordered input-sm w-24"
                                        min="0"
                                        aria-label="Priority for {{ $offshore->name }}"
                                        form="offshore-priority-form"
                                    >
                                @else
                                    <span class="badge badge-ghost">{{ $offshore->priority }}</span>
                                @endif
                            </td>
                            <td>
                                <div class="font-semibold">{{ $offshore->name }}</div>
                                <div class="text-sm text-base-content/60">Created {{ $offshore->created_at?->format('M d, Y') ?? 'Unknown' }}</div>
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
                                    <div class="text-sm text-base-content/60">
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
                                    <span class="text-sm text-base-content/60">No cached balances yet.</span>
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
                                    <span class="text-sm text-base-content/60">No guardrails</span>
                                @endif
                            </td>
                            @if($canManageOffshores)
                                <td class="text-right">
                                    <div class="flex flex-wrap justify-end gap-2">
                                        <button class="btn btn-outline btn-sm" type="button" data-dialog-open="editOffshoreModal-{{ $offshore->id }}" title="Edit offshore">
                                            <x-icon name="o-pencil" class="size-4" />
                                        </button>
                                        <form action="{{ route('admin.offshores.refresh', $offshore) }}" method="POST">
                                            @csrf
                                            <button type="submit" class="btn btn-outline btn-primary btn-sm" title="Refresh balances">
                                                <x-icon name="o-arrow-path" class="size-4" />
                                            </button>
                                        </form>
                                        <button
                                            type="button"
                                            class="btn btn-outline btn-info btn-sm"
                                            data-action="open-transfer"
                                            data-source-type="{{ \App\Models\OffshoreTransfer::TYPE_OFFSHORE }}"
                                            data-source-id="{{ $offshore->id }}"
                                            data-destination-type="{{ \App\Models\OffshoreTransfer::TYPE_MAIN }}"
                                            title="Transfer to main bank"
                                        >
                                            <x-icon name="o-arrow-up-tray" class="size-4" />
                                        </button>
                                        <button
                                            type="button"
                                            class="btn btn-outline btn-success btn-sm"
                                            data-action="open-transfer"
                                            data-source-type="{{ \App\Models\OffshoreTransfer::TYPE_MAIN }}"
                                            data-destination-type="{{ \App\Models\OffshoreTransfer::TYPE_OFFSHORE }}"
                                            data-destination-id="{{ $offshore->id }}"
                                            title="Send funds from main bank"
                                        >
                                            <x-icon name="o-arrow-down-tray" class="size-4" />
                                        </button>
                                        <form action="{{ route('admin.offshores.sweep', $offshore) }}" method="POST" onsubmit="return confirm('Sweep the entire main bank into {{ $offshore->name }}? This cannot be undone.');">
                                            @csrf
                                            <button type="submit" class="btn btn-outline btn-neutral btn-sm" title="Sweep main bank">
                                                <x-icon name="o-building-library" class="size-4" />
                                            </button>
                                        </form>
                                        <form action="{{ route('admin.offshores.toggle', $offshore) }}" method="POST">
                                            @csrf
                                            <button type="submit" class="btn btn-outline btn-warning btn-sm" title="Toggle availability">
                                                <x-icon name="o-power" class="size-4" />
                                            </button>
                                        </form>
                                        <form action="{{ route('admin.offshores.destroy', $offshore) }}" method="POST" onsubmit="return confirm('Delete {{ $offshore->name }}? This action cannot be undone.');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-outline btn-error btn-sm" title="Delete offshore">
                                                <x-icon name="o-trash" class="size-4" />
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $canManageOffshores ? 7 : 6 }}" class="py-6 text-center text-base-content/60">
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
                            <button type="submit" class="btn btn-outline btn-sm">
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
                    <div class="text-sm text-base-content/60">
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
                    <p class="text-sm text-base-content/60">No cached main bank data yet.</p>
                @endif

                @if($canManageOffshores)
                    <div class="mt-4 rounded-box border border-base-300 bg-base-200/50 p-4">
                        <p class="mb-3 text-sm text-base-content/60">
                            Bridge funds between the main bank and offshores. Transfers are executed instantly using the configured API keys.
                        </p>
                        <button class="btn btn-outline w-full" type="button" data-dialog-open="manualTransferModal">
                            <x-icon name="o-banknotes" class="size-4" />
                            Start Transfer
                        </button>
                    </div>
                @endif
            </x-card>

            <x-card title="Recent Manual Transfers" :subtitle="'Last ' . $transfers->count() . ' records'">
                <div class="overflow-x-auto rounded-box border border-base-300">
                    <table class="table table-zebra">
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
                                        <x-icon name="o-arrow-right" class="size-4 text-base-content/50" />
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
                                        <div class="mt-2 text-sm text-base-content/60">{{ $transfer->message }}</div>
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
                                <td colspan="5" class="py-6 text-center text-base-content/60">No transfers recorded yet.</td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </x-card>
        </div>
    </div>

    @if($canManageOffshores)
        <dialog id="createOffshoreModal" class="modal">
            <div class="modal-box max-w-4xl">
                <form action="{{ route('admin.offshores.store') }}" method="POST" autocomplete="off" class="space-y-6">
                    @csrf
                    <input type="hidden" name="modal_context" value="create">

                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h3 class="text-lg font-semibold">Add Offshore</h3>
                            <p class="text-sm text-base-content/60">Create an offshore entry and define any transfer guardrails.</p>
                        </div>
                        <button type="button" class="btn btn-circle btn-ghost btn-sm" data-dialog-close="createOffshoreModal">✕</button>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <label class="block space-y-2">
                            <span class="text-sm font-medium">Name</span>
                            <input type="text" class="input input-bordered w-full" name="name" value="{{ $modalContext === 'create' ? old('name') : '' }}" required>
                        </label>
                        <label class="block space-y-2">
                            <span class="text-sm font-medium">Alliance ID</span>
                            <input type="number" class="input input-bordered w-full" name="alliance_id" value="{{ $modalContext === 'create' ? old('alliance_id') : '' }}" min="1" required>
                        </label>
                        <label class="block space-y-2">
                            <span class="text-sm font-medium">API Key</span>
                            <input type="text" class="input input-bordered w-full" name="api_key" value="{{ $modalContext === 'create' ? old('api_key') : '' }}" required>
                            <span class="text-xs text-base-content/60">Stored encrypted. Paste the offshore bot API key.</span>
                        </label>
                        <label class="block space-y-2">
                            <span class="text-sm font-medium">Mutation Key</span>
                            <input type="text" class="input input-bordered w-full" name="mutation_key" value="{{ $modalContext === 'create' ? old('mutation_key') : '' }}" required>
                        </label>
                        <label class="block space-y-2">
                            <span class="text-sm font-medium">Priority</span>
                            <input type="number" class="input input-bordered w-full" name="priority" value="{{ $modalContext === 'create' ? old('priority', 0) : 0 }}" min="0">
                        </label>
                        <label class="block space-y-2">
                            <span class="text-sm font-medium">Enabled</span>
                            @php $createEnabled = $modalContext === 'create' ? (int) old('enabled', 1) : 1; @endphp
                            <select class="select select-bordered w-full" name="enabled">
                                <option value="1" {{ $createEnabled === 1 ? 'selected' : '' }}>Yes</option>
                                <option value="0" {{ $createEnabled === 0 ? 'selected' : '' }}>No</option>
                            </select>
                        </label>
                    </div>

                    <div class="space-y-3">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <h4 class="font-semibold">Guardrails</h4>
                                <p class="text-sm text-base-content/60">Prevent automated withdrawals from dropping a resource below a minimum.</p>
                            </div>
                            <button class="btn btn-outline btn-sm" type="button" data-action="add-guardrail" data-target="#create-guardrail-container">
                                <x-icon name="o-plus-circle" class="size-4" />
                                Add Guardrail
                            </button>
                        </div>
                        @php $createGuardrails = $modalContext === 'create' ? old('guardrails', []) : []; @endphp
                        <input type="hidden" name="guardrails" value="">
                        <div id="create-guardrail-container" class="guardrail-container space-y-3" data-next-index="{{ count($createGuardrails) }}">
                            @foreach($createGuardrails as $index => $guardrail)
                                @include('admin.offshores.partials.guardrail-row', ['index' => $index, 'guardrail' => $guardrail, 'resources' => $guardrailResources])
                            @endforeach
                        </div>
                    </div>

                    <div class="flex justify-end gap-2">
                        <button type="button" class="btn btn-ghost" data-dialog-close="createOffshoreModal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Offshore</button>
                    </div>
                </form>
            </div>
            <form method="dialog" class="modal-backdrop"><button>close</button></form>
        </dialog>

        @foreach($offshores as $offshore)
            <dialog id="editOffshoreModal-{{ $offshore->id }}" class="modal">
                <div class="modal-box max-w-4xl">
                    <form action="{{ route('admin.offshores.update', $offshore) }}" method="POST" autocomplete="off" class="space-y-6">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="modal_context" value="edit-{{ $offshore->id }}">

                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <h3 class="text-lg font-semibold">Edit {{ $offshore->name }}</h3>
                                <p class="text-sm text-base-content/60">Update credentials, ordering, and resource guardrails.</p>
                            </div>
                            <button type="button" class="btn btn-circle btn-ghost btn-sm" data-dialog-close="editOffshoreModal-{{ $offshore->id }}">✕</button>
                        </div>

                        @php $editContext = $modalContext === 'edit-' . $offshore->id; @endphp
                        <div class="grid gap-4 md:grid-cols-2">
                            <label class="block space-y-2">
                                <span class="text-sm font-medium">Name</span>
                                <input type="text" class="input input-bordered w-full" name="name" value="{{ $editContext ? old('name', $offshore->name) : $offshore->name }}">
                            </label>
                            <label class="block space-y-2">
                                <span class="text-sm font-medium">Alliance ID</span>
                                <input type="number" class="input input-bordered w-full" name="alliance_id" value="{{ $editContext ? old('alliance_id', $offshore->alliance_id) : $offshore->alliance_id }}" min="1">
                            </label>
                            <label class="block space-y-2">
                                <span class="text-sm font-medium">API Key</span>
                                <input type="text" class="input input-bordered w-full" name="api_key" placeholder="Leave blank to keep current key">
                            </label>
                            <label class="block space-y-2">
                                <span class="text-sm font-medium">Mutation Key</span>
                                <input type="text" class="input input-bordered w-full" name="mutation_key" placeholder="Leave blank to keep current key">
                            </label>
                            <label class="block space-y-2">
                                <span class="text-sm font-medium">Priority</span>
                                <input type="number" class="input input-bordered w-full" name="priority" value="{{ $editContext ? old('priority', $offshore->priority) : $offshore->priority }}" min="0">
                            </label>
                            <label class="block space-y-2">
                                <span class="text-sm font-medium">Enabled</span>
                                @php $editEnabled = $editContext ? (int) old('enabled', $offshore->enabled ? 1 : 0) : ($offshore->enabled ? 1 : 0); @endphp
                                <select class="select select-bordered w-full" name="enabled">
                                    <option value="1" {{ $editEnabled === 1 ? 'selected' : '' }}>Yes</option>
                                    <option value="0" {{ $editEnabled === 0 ? 'selected' : '' }}>No</option>
                                </select>
                            </label>
                        </div>

                        <div class="space-y-3">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <h4 class="font-semibold">Guardrails</h4>
                                    <p class="text-sm text-base-content/60">Leave guardrails empty to allow the service to manage resources freely.</p>
                                </div>
                                <button class="btn btn-outline btn-sm" type="button" data-action="add-guardrail" data-target="#edit-guardrail-container-{{ $offshore->id }}">
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
                            <div id="edit-guardrail-container-{{ $offshore->id }}" class="guardrail-container space-y-3" data-next-index="{{ count($editGuardrails) }}">
                                @foreach($editGuardrails as $index => $guardrail)
                                    @include('admin.offshores.partials.guardrail-row', ['index' => $index, 'guardrail' => $guardrail, 'resources' => $guardrailResources])
                                @endforeach
                            </div>
                        </div>

                        <div class="flex justify-end gap-2">
                            <button type="button" class="btn btn-ghost" data-dialog-close="editOffshoreModal-{{ $offshore->id }}">Cancel</button>
                            <button type="submit" class="btn btn-primary">Save Changes</button>
                        </div>
                    </form>
                </div>
                <form method="dialog" class="modal-backdrop"><button>close</button></form>
            </dialog>
        @endforeach

        <dialog id="manualTransferModal" class="modal">
            <div class="modal-box max-w-4xl">
                <form action="{{ route('admin.offshores.transfer') }}" method="POST" autocomplete="off" class="space-y-6">
                    @csrf

                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h3 class="text-lg font-semibold">Manual Offshore Transfer</h3>
                            <p class="text-sm text-base-content/60">Move funds between the main bank and offshores.</p>
                        </div>
                        <button type="button" class="btn btn-circle btn-ghost btn-sm" data-dialog-close="manualTransferModal">✕</button>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <label class="block space-y-2">
                            <span class="text-sm font-medium">Source</span>
                            <select class="select select-bordered w-full" name="source_type" id="transfer-source-type" required>
                                <option value="{{ \App\Models\OffshoreTransfer::TYPE_MAIN }}">Main Bank</option>
                                @foreach($offshores as $offshore)
                                    <option value="{{ \App\Models\OffshoreTransfer::TYPE_OFFSHORE }}" data-offshore-id="{{ $offshore->id }}">
                                        {{ $offshore->name }}
                                    </option>
                                @endforeach
                            </select>
                            <input type="hidden" name="source_offshore_id" id="transfer-source-id">
                        </label>
                        <label class="block space-y-2">
                            <span class="text-sm font-medium">Destination</span>
                            <select class="select select-bordered w-full" name="destination_type" id="transfer-destination-type" required>
                                <option value="{{ \App\Models\OffshoreTransfer::TYPE_MAIN }}">Main Bank</option>
                                @foreach($offshores as $offshore)
                                    <option value="{{ \App\Models\OffshoreTransfer::TYPE_OFFSHORE }}" data-offshore-id="{{ $offshore->id }}">
                                        {{ $offshore->name }}
                                    </option>
                                @endforeach
                            </select>
                            <input type="hidden" name="destination_offshore_id" id="transfer-destination-id">
                        </label>
                    </div>

                    <label class="block space-y-2">
                        <span class="text-sm font-medium">Operator Note <span class="text-base-content/60">(optional)</span></span>
                        <input type="text" class="input input-bordered w-full" name="note" placeholder="Visible in bank records and audit logs">
                    </label>

                    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach($resources as $resource)
                            <label class="block space-y-2">
                                <span class="text-sm font-medium text-capitalize">{{ $resource }}</span>
                                <div class="join w-full">
                                    @if($resource === 'money')
                                        <span class="join-item flex items-center border border-base-300 bg-base-200 px-3 text-sm">$</span>
                                    @endif
                                    <input
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        class="input input-bordered join-item w-full"
                                        name="resources[{{ $resource }}]"
                                        placeholder="0.00"
                                    >
                                </div>
                            </label>
                        @endforeach
                    </div>

                    <p class="text-sm text-base-content/60">Only resources with amounts greater than zero will be transferred.</p>

                    <div class="flex justify-end gap-2">
                        <button type="button" class="btn btn-ghost" data-dialog-close="manualTransferModal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Execute Transfer</button>
                    </div>
                </form>
            </div>
            <form method="dialog" class="modal-backdrop"><button>close</button></form>
        </dialog>
    @endif

    <template id="guardrail-row-template">
        <div class="guardrail-row grid gap-3 rounded-box border border-base-300 p-3 md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] md:items-end">
            <label class="block space-y-2">
                <span class="text-sm font-medium">Resource</span>
                <select class="select select-bordered w-full" name="guardrails[__INDEX__][resource]" required>
                    @foreach($guardrailResources as $resource)
                        <option value="{{ $resource }}">{{ ucfirst($resource) }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block space-y-2">
                <span class="text-sm font-medium">Minimum Amount</span>
                <input type="number" step="0.01" min="0" class="input input-bordered w-full" name="guardrails[__INDEX__][minimum_amount]" required>
            </label>
            <button type="button" class="btn btn-outline btn-error btn-sm md:self-end" data-action="remove-guardrail">
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
                    const content = guardrailTemplate.innerHTML.replace(/__INDEX__/g, String(nextIndex));
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

            const sourceTypeSelect = document.getElementById('transfer-source-type');
            const destinationTypeSelect = document.getElementById('transfer-destination-type');
            const sourceIdInput = document.getElementById('transfer-source-id');
            const destinationIdInput = document.getElementById('transfer-destination-id');

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
