@extends('layouts.admin')

@section('title', 'Member Transfer #'.$memberTransfer->id)

@section('content')
    @php
        $status = match ($memberTransfer->status) {
            \App\Models\MemberTransfer::STATUS_PENDING => ['label' => 'Awaiting recipient', 'intent' => 'pending', 'icon' => 'clock'],
            \App\Models\MemberTransfer::STATUS_ACCEPTED => ['label' => 'Accepted', 'intent' => 'success', 'icon' => 'check-circle'],
            \App\Models\MemberTransfer::STATUS_DECLINED => ['label' => 'Declined', 'intent' => 'failure', 'icon' => 'x-circle'],
            \App\Models\MemberTransfer::STATUS_CANCELED => ['label' => 'Canceled', 'intent' => 'neutral', 'icon' => 'minus-circle'],
            default => ['label' => str($memberTransfer->status)->headline()->toString(), 'intent' => 'neutral', 'icon' => 'eye'],
        };
    @endphp

    <header class="nexus-page-header">
        <div class="nexus-page-header__copy">
            <p class="nexus-eyebrow">Member transfer</p>
            <h1 class="nexus-page-title">Transfer #{{ $memberTransfer->id }}</h1>
            <p class="nexus-page-summary">
                Requested <x-time.display :value="$memberTransfer->created_at" :server-now="now()" label="Transfer requested" />.
                The recipient must accept or decline the transfer.
            </p>
        </div>
        <div class="nexus-page-header__actions">
            <x-nexus-status :label="$status['label']" :intent="$status['intent']" :icon="$status['icon']" />
            <a href="{{ route('admin.work-queue.index', ['type' => 'member_transfers']) }}" class="btn btn-outline btn-sm">
                Back to work queue
            </a>
        </div>
    </header>

    <div class="grid gap-5 lg:grid-cols-2">
        <section class="nexus-panel" aria-labelledby="transfer-parties-title">
            <div class="nexus-panel__header">
                <h2 id="transfer-parties-title" class="nexus-section-title">Transfer details</h2>
            </div>
            <dl class="grid gap-4 px-5 pb-5 sm:grid-cols-2">
                <div>
                    <dt class="text-sm font-medium text-base-content/70">From</dt>
                    <dd class="mt-1 font-semibold">{{ $memberTransfer->fromNation?->leader_name ?? 'Nation #'.$memberTransfer->from_nation_id }}</dd>
                    <dd class="text-sm text-base-content/70">{{ $memberTransfer->fromAccount?->name ?? 'Account #'.$memberTransfer->from_account_id }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-base-content/70">To</dt>
                    <dd class="mt-1 font-semibold">{{ $memberTransfer->toNation?->leader_name ?? 'Nation #'.$memberTransfer->to_nation_id }}</dd>
                    <dd class="text-sm text-base-content/70">{{ $memberTransfer->toAccount?->name ?? 'Account #'.$memberTransfer->to_account_id }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-base-content/70">Requested by</dt>
                    <dd class="mt-1">{{ $memberTransfer->createdBy?->name ?? 'User #'.$memberTransfer->created_by }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-base-content/70">Current owner</dt>
                    <dd class="mt-1">{{ $memberTransfer->toNation?->leader_name ?? 'Nation #'.$memberTransfer->to_nation_id }}</dd>
                </div>
            </dl>
        </section>

        <section class="nexus-panel" aria-labelledby="transfer-vector-title">
            <div class="nexus-panel__header">
                <h2 id="transfer-vector-title" class="nexus-section-title">Resources</h2>
            </div>
            <div class="overflow-x-auto px-5 pb-5">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th scope="col">Resource</th>
                            <th scope="col" class="text-right">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach (\App\Services\PWHelperService::resources() as $resource)
                            @if ((float) $memberTransfer->{$resource} !== 0.0)
                                <tr>
                                    <th scope="row" class="font-medium">{{ str($resource)->headline() }}</th>
                                    <td class="text-right">
                                        {{ $resource === 'money' ? '$' : '' }}{{ number_format((float) $memberTransfer->{$resource}, 2) }}
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    @if ($memberTransfer->status === \App\Models\MemberTransfer::STATUS_PENDING)
        <section class="nexus-panel mt-5" aria-labelledby="transfer-recovery-title">
            <div class="nexus-panel__header">
                <div>
                    <h2 id="transfer-recovery-title" class="nexus-section-title">Cancel transfer</h2>
                    <p class="nexus-body-muted mt-1">Cancel only if the transfer should not continue. Held resources will return to the source account.</p>
                </div>
                <form
                    method="POST"
                    action="{{ route('admin.member-transfers.cancel', $memberTransfer) }}"
                    data-confirm="Cancel this pending member transfer and return its held resources to the source account?"
                    data-confirm-title="Cancel transfer?"
                    data-confirm-label="Cancel and refund"
                >
                    @csrf
                    <button type="submit" class="btn btn-error btn-outline btn-sm">Cancel and refund</button>
                </form>
            </div>
        </section>
    @endif
@endsection
