@php
    use App\Models\AccountStatementExport;

    $queryParameters = array_filter([
        'account_id' => $account->id,
        'from' => $filters['from'],
        'to' => $filters['to'],
        'type' => $filters['type'],
        'status' => $filters['status'],
    ], fn ($value) => $value !== null && $value !== '');

    $statementStatus = static fn (string $status, string $label): array => match ($status) {
        'completed' => ['intent' => 'success', 'icon' => 'check-circle', 'label' => $label],
        'pending' => ['intent' => 'pending', 'icon' => 'clock', 'label' => $label],
        'needs_reconciliation' => ['intent' => 'warning', 'icon' => 'exclamation-triangle', 'label' => $label],
        'refunded' => ['intent' => 'warning', 'icon' => 'arrow-path', 'label' => $label],
        'denied', 'failed' => ['intent' => 'failure', 'icon' => 'x-circle', 'label' => $label],
        default => ['intent' => 'neutral', 'icon' => 'minus-circle', 'label' => $label],
    };

    $exportStatus = static fn (string $status): array => match ($status) {
        AccountStatementExport::STATUS_PENDING => ['intent' => 'pending', 'icon' => 'clock', 'label' => 'Pending'],
        AccountStatementExport::STATUS_PROCESSING => ['intent' => 'active', 'icon' => 'arrow-path', 'label' => 'Processing'],
        AccountStatementExport::STATUS_COMPLETED => ['intent' => 'success', 'icon' => 'check-circle', 'label' => 'Ready'],
        AccountStatementExport::STATUS_FAILED => ['intent' => 'failure', 'icon' => 'x-circle', 'label' => 'Failed'],
        default => ['intent' => 'neutral', 'icon' => 'archive-box', 'label' => 'Expired'],
    };
@endphp

@extends('layouts.main')

@section('content')
    <main class="mx-auto w-full min-w-0 space-y-6" aria-labelledby="statement-heading">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <p class="text-xs uppercase tracking-wide text-base-content/60">Finance</p>
                <h1 id="statement-heading" class="text-3xl font-bold">Personal account statement</h1>
                <p class="mt-2 max-w-3xl text-sm text-base-content/70">
                    Review and export activity for accounts owned by your nation. Filters apply to the table, print view, and CSV.
                </p>
            </div>
            <a href="{{ route('accounts.view', $account) }}" class="btn btn-ghost">Back to {{ $account->name }}</a>
        </div>

        @if($errors->any())
            <div class="alert alert-error" role="alert">
                <div>
                    <h2 class="font-semibold">Check the statement filters</h2>
                    <ul class="mt-1 list-disc pl-5 text-sm">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endif

        <section class="rounded-xl border border-base-300 bg-base-100 p-5 shadow-sm" aria-labelledby="statement-filters-heading">
            <h2 id="statement-filters-heading" class="text-lg font-semibold">Statement filters</h2>
            <form method="GET" action="{{ route('accounts.statements.index') }}" class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-5">
                <label class="form-control w-full">
                    <span class="label-text mb-1 font-medium">Account</span>
                    <select name="account_id" class="select select-bordered w-full">
                        @foreach($accounts as $ownedAccount)
                            <option value="{{ $ownedAccount->id }}" @selected($ownedAccount->is($account))>
                                {{ $ownedAccount->name }} (ID {{ $ownedAccount->id }})
                            </option>
                        @endforeach
                    </select>
                </label>

                <label class="form-control w-full">
                    <span class="label-text mb-1 font-medium">From date</span>
                    <input type="date" name="from" value="{{ $filters['from'] }}" class="input input-bordered w-full">
                </label>

                <label class="form-control w-full">
                    <span class="label-text mb-1 font-medium">To date</span>
                    <input type="date" name="to" value="{{ $filters['to'] }}" class="input input-bordered w-full">
                </label>

                <label class="form-control w-full">
                    <span class="label-text mb-1 font-medium">Transaction type</span>
                    <select name="type" class="select select-bordered w-full">
                        <option value="">All types</option>
                        @foreach($typeOptions as $value => $label)
                            <option value="{{ $value }}" @selected($filters['type'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="form-control w-full">
                    <span class="label-text mb-1 font-medium">Status</span>
                    <select name="status" class="select select-bordered w-full">
                        <option value="">All statuses</option>
                        @foreach($statusOptions as $value => $label)
                            <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <div class="flex flex-wrap gap-2 md:col-span-2 xl:col-span-5">
                    <button type="submit" class="btn btn-primary">Apply filters</button>
                    <a href="{{ route('accounts.statements.index', ['account_id' => $account->id]) }}" class="btn btn-ghost">Clear filters</a>
                    <a href="{{ route('accounts.statements.print', $queryParameters) }}" class="btn btn-outline" target="_blank" rel="noopener">
                        Printable statement
                    </a>
                </div>
            </form>

            <form method="POST" action="{{ route('accounts.statements.exports.store') }}" class="mt-3">
                @csrf
                @foreach($queryParameters as $name => $value)
                    <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                @endforeach
                <button type="submit" class="btn btn-secondary">Download or prepare CSV</button>
                <p class="mt-2 text-xs text-base-content/60">
                    Small files download immediately. Large files are prepared privately and expire after a limited time.
                </p>
            </form>
        </section>

        <section class="alert alert-info items-start" aria-labelledby="statement-balance-heading">
            <div>
                <h2 id="statement-balance-heading" class="font-semibold">Opening and closing balances unavailable</h2>
                <p class="text-sm">
                    Nexus does not retain authoritative historical balance snapshots for this account. The balances below are the
                    current observed values, not opening or closing balances for {{ $filters['from'] }} through {{ $filters['to'] }}.
                </p>
            </div>
        </section>

        <section class="rounded-xl border border-base-300 bg-base-100 p-5 shadow-sm" aria-labelledby="current-balances-heading">
            <h2 id="current-balances-heading" class="text-lg font-semibold">Current observed balances</h2>
            <div class="mt-3 overflow-x-auto">
                <table class="table table-sm" data-sortable="false">
                    <caption class="sr-only">Current observed resource balances for {{ $account->name }}</caption>
                    <thead>
                    <tr>
                        @foreach($resourceColumns as $resource)
                            <th scope="col">{{ ucfirst($resource) }}</th>
                        @endforeach
                    </tr>
                    </thead>
                    <tbody>
                    <tr>
                        @foreach($resourceColumns as $resource)
                            @php $amount = (float) $account->{$resource}; @endphp
                            <td>
                                @if($resource === 'money')
                                    {{ $amount < 0 ? '-$' : '$' }}{{ number_format(abs($amount), 2) }}
                                @else
                                    {{ number_format($amount, 2) }}
                                @endif
                            </td>
                        @endforeach
                    </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="rounded-xl border border-base-300 bg-base-100 p-5 shadow-sm" aria-labelledby="statement-activity-heading">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="statement-activity-heading" class="text-lg font-semibold">Filtered activity</h2>
                <p class="text-sm text-base-content/60">{{ number_format($rows->total()) }} records</p>
            </div>

            @if($rows->isEmpty())
                <div class="mt-4 rounded-lg border border-dashed border-base-300 p-8 text-center">
                    <h3 class="font-semibold">No activity matches these filters</h3>
                    <p class="mt-1 text-sm text-base-content/70">Try a wider date range or clear the type and status filters.</p>
                </div>
            @else
                <div class="mt-4 overflow-x-auto rounded-lg border border-base-300">
                    <table class="table table-sm table-zebra" data-sortable="false">
                        <caption class="sr-only">Account activity matching the selected statement filters</caption>
                        <thead>
                        <tr>
                            <th scope="col">Date</th>
                            <th scope="col">Type</th>
                            <th scope="col">Status</th>
                            <th scope="col">Direction</th>
                            <th scope="col">Reference ID</th>
                            @foreach($resourceColumns as $resource)
                                <th scope="col">{{ ucfirst($resource) }}</th>
                            @endforeach
                            <th scope="col">Description</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($rows as $row)
                            <tr>
                                <td class="whitespace-nowrap">
                                    <x-time.display :value="$row->occurredAt" label="Transaction occurred" />
                                </td>
                                <td>{{ $row->typeLabel }}</td>
                                <td>
                                    @php $rowStatus = $statementStatus($row->status, $row->statusLabel); @endphp
                                    <x-nexus-status
                                        :intent="$rowStatus['intent']"
                                        :icon="$rowStatus['icon']"
                                        :label="$rowStatus['label']"
                                    />
                                </td>
                                <td>{{ ucfirst($row->direction) }}</td>
                                <td>
                                    <x-copy-action :value="$row->referenceId" label="reference ID" />
                                </td>
                                @foreach($resourceColumns as $resource)
                                    @php $amount = $row->resources[$resource]; @endphp
                                    <td class="whitespace-nowrap">
                                        @if($resource === 'money')
                                            {{ $amount < 0 ? '-$' : '$' }}{{ number_format(abs($amount), 2) }}
                                        @else
                                            {{ number_format($amount, 2) }}
                                        @endif
                                    </td>
                                @endforeach
                                <td>{{ $row->description ?? '—' }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="mt-4">{{ $rows->links() }}</div>
            @endif
        </section>

        <section class="rounded-xl border border-base-300 bg-base-100 p-5 shadow-sm" aria-labelledby="statement-exports-heading">
            <h2 id="statement-exports-heading" class="text-lg font-semibold">Recent large exports</h2>
            @if($exports->isEmpty())
                <p class="mt-2 text-sm text-base-content/70">No large exports have been requested for this account.</p>
            @else
                <ul class="mt-3 space-y-3">
                    @foreach($exports as $export)
                        <li class="flex flex-col gap-2 rounded-lg border border-base-300 p-3 sm:flex-row sm:items-center sm:justify-between">
                            <div @if(in_array($export->status, [AccountStatementExport::STATUS_PENDING, AccountStatementExport::STATUS_PROCESSING], true)) aria-live="polite" @endif>
                                <a href="{{ route('accounts.statements.exports.show', $export) }}" class="font-medium link link-primary">
                                    Requested <x-time.display :value="$export->created_at" label="Export requested" />
                                </a>
                                <p class="text-sm text-base-content/70">
                                    @php $exportPresentation = $exportStatus($export->status); @endphp
                                    <x-nexus-status
                                        :intent="$exportPresentation['intent']"
                                        :icon="$exportPresentation['icon']"
                                        :label="$exportPresentation['label']"
                                    />
                                    @if($export->row_count !== null)
                                        · {{ number_format($export->row_count) }} rows
                                    @endif
                                </p>
                            </div>
                            @if($export->isAvailable())
                                <a href="{{ route('accounts.statements.exports.download', $export) }}" class="btn btn-sm btn-primary">Download CSV</a>
                            @elseif($export->status === AccountStatementExport::STATUS_FAILED)
                                <span class="text-sm text-error">{{ $export->failure_message }}</span>
                            @elseif($export->status === AccountStatementExport::STATUS_EXPIRED)
                                <span class="text-sm text-base-content/60">Expired</span>
                            @else
                                <span class="loading loading-spinner loading-sm" aria-label="Export is being prepared"></span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </main>
@endsection
