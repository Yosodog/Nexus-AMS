@php use App\Services\PWHelperService; @endphp
<x-utils.card title="Member transfer approvals" extraClasses="mb-2">
    <p class="text-sm text-base-content/70 mb-4">Approve incoming transfers from alliance members or cancel ones you sent.</p>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="space-y-4">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-base-content/60">Incoming</h3>
            @forelse ($incomingMemberTransfers as $transfer)
                <div class="rounded-xl border border-base-300 bg-base-200/50 p-4 space-y-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <p class="text-sm font-semibold">
                                {{ $transfer->fromNation?->nation_name ?? 'Unknown Nation' }}
                            </p>
                            <p class="text-xs text-base-content/70">
                                From {{ $transfer->fromAccount?->name ?? 'Unknown Account' }}
                            </p>
                        </div>
                        <span class="text-xs text-base-content/60">{{ $transfer->created_at?->diffForHumans() }}</span>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        @foreach (PWHelperService::resources() as $resource)
                            @if ((float) $transfer->{$resource} > 0)
                                <span class="badge badge-ghost">
                                    {{ ucfirst($resource) }}: {{ number_format($transfer->{$resource}, 2) }}
                                </span>
                            @endif
                        @endforeach
                    </div>
                    <div class="flex flex-wrap gap-3">
                        <form method="POST" action="{{ route('member-transfers.accept', $transfer) }}">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-primary">Accept</button>
                        </form>
                        <form method="POST" action="{{ route('member-transfers.decline', $transfer) }}">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-outline">Decline</button>
                        </form>
                    </div>
                </div>
            @empty
                <div class="rounded-xl border border-dashed border-base-300 p-4 text-sm text-base-content/60">
                    No incoming transfers awaiting approval.
                </div>
            @endforelse
        </div>
        <div class="space-y-4">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-base-content/60">Outgoing</h3>
            @forelse ($outgoingMemberTransfers as $transfer)
                <div class="rounded-xl border border-base-300 bg-base-200/50 p-4 space-y-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <p class="text-sm font-semibold">
                                {{ $transfer->toNation?->nation_name ?? 'Unknown Nation' }}
                            </p>
                            <p class="text-xs text-base-content/70">
                                To {{ $transfer->toAccount?->name ?? 'Unknown Account' }}
                            </p>
                        </div>
                        <span class="text-xs text-base-content/60">{{ $transfer->created_at?->diffForHumans() }}</span>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        @foreach (PWHelperService::resources() as $resource)
                            @if ((float) $transfer->{$resource} > 0)
                                <span class="badge badge-ghost">
                                    {{ ucfirst($resource) }}: {{ number_format($transfer->{$resource}, 2) }}
                                </span>
                            @endif
                        @endforeach
                    </div>
                    <div class="flex flex-wrap gap-3">
                        <form method="POST" action="{{ route('member-transfers.cancel', $transfer) }}">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-outline">Cancel</button>
                        </form>
                    </div>
                </div>
            @empty
                <div class="rounded-xl border border-dashed border-base-300 p-4 text-sm text-base-content/60">
                    No outgoing transfers awaiting approval.
                </div>
            @endforelse
        </div>
    </div>
</x-utils.card>
