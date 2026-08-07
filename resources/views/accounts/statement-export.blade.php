@php
    use App\Models\AccountStatementExport;

    $statusPresentation = match ($export->status) {
        AccountStatementExport::STATUS_PENDING => ['intent' => 'pending', 'icon' => 'clock', 'label' => 'Pending'],
        AccountStatementExport::STATUS_PROCESSING => ['intent' => 'active', 'icon' => 'arrow-path', 'label' => 'Processing'],
        AccountStatementExport::STATUS_COMPLETED => ['intent' => 'success', 'icon' => 'check-circle', 'label' => 'Ready'],
        AccountStatementExport::STATUS_FAILED => ['intent' => 'failure', 'icon' => 'x-circle', 'label' => 'Failed'],
        default => ['intent' => 'neutral', 'icon' => 'archive-box', 'label' => 'Expired'],
    };
@endphp

@extends('layouts.main')

@section('content')
    <main class="mx-auto max-w-3xl space-y-6" aria-labelledby="export-status-heading">
        <div>
            <p class="text-xs uppercase tracking-wide text-base-content/60">Account statement export</p>
            <h1 id="export-status-heading" class="text-3xl font-bold">Export status</h1>
            <p class="mt-2 text-sm text-base-content/70">
                {{ $account->name }} · requested
                <x-time.display :value="$export->created_at" label="Export requested" :show-exact="true" />
            </p>
        </div>

        <section class="rounded-xl border border-base-300 bg-base-100 p-6 shadow-sm" aria-live="polite">
            <x-nexus-status
                :intent="$statusPresentation['intent']"
                :icon="$statusPresentation['icon']"
                :label="$statusPresentation['label']"
                class="mb-4"
            />
            @if($export->isAvailable())
                <h2 class="text-xl font-semibold">Ready to download</h2>
                <p class="mt-2 text-sm text-base-content/70">
                    {{ number_format((int) $export->row_count) }} rows. This private file expires
                    <x-time.display :value="$export->expires_at" label="Download expires" :show-exact="true" />.
                </p>
                <a href="{{ route('accounts.statements.exports.download', $export) }}" class="btn btn-primary mt-4">Download CSV</a>
            @elseif(in_array($export->status, [AccountStatementExport::STATUS_PENDING, AccountStatementExport::STATUS_PROCESSING], true))
                <div class="flex items-start gap-3">
                    <span class="loading loading-spinner loading-md" aria-hidden="true"></span>
                    <div>
                        <h2 class="text-xl font-semibold">{{ $export->status === AccountStatementExport::STATUS_PENDING ? 'Waiting to start' : 'Preparing your export' }}</h2>
                        <p class="mt-2 text-sm text-base-content/70">You can leave this page and return later. Duplicate requests are ignored.</p>
                    </div>
                </div>
            @elseif($export->status === AccountStatementExport::STATUS_FAILED)
                <h2 class="text-xl font-semibold text-error">Export failed</h2>
                <p class="mt-2 text-sm">{{ $export->failure_message ?: 'The export could not be prepared. Try again.' }}</p>
            @else
                <h2 class="text-xl font-semibold">Export expired</h2>
                <p class="mt-2 text-sm text-base-content/70">The private download is no longer available. Create a new export.</p>
            @endif
        </section>

        <div class="flex flex-wrap gap-2">
            @if(in_array($export->status, [AccountStatementExport::STATUS_PENDING, AccountStatementExport::STATUS_PROCESSING], true))
                <a href="{{ route('accounts.statements.exports.show', $export) }}" class="btn btn-primary">Refresh status</a>
            @endif
            <a href="{{ route('accounts.statements.index', ['account_id' => $export->account_id, ...$export->filters]) }}" class="btn btn-ghost">
                Back to statement
            </a>
        </div>
    </main>
@endsection
