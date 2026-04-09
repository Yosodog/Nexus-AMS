@php use App\Services\PWHelperService; @endphp
@extends('layouts.admin')

@section('content')
    <x-header title="War Aid Management" separator>
        <x-slot:subtitle>Review pending requests, issue manual aid, and keep wartime payouts organized.</x-slot:subtitle>
        <x-slot:actions>
            <form method="POST" action="{{ route('admin.war-aid.toggle') }}">
                @csrf
                <button class="btn btn-{{ $enabled ? 'warning' : 'success' }} btn-sm">
                    {{ $enabled ? 'Disable War Aid' : 'Enable War Aid' }}
                </button>
            </form>
        </x-slot:actions>
    </x-header>

    <div class="space-y-6">
        @if ($pending->isNotEmpty())
            <x-card title="Pending Requests">
                <div class="space-y-4">
                    @foreach ($pending as $req)
                        @php
                            $nation = $req->nation;
                            $account = $req->account;
                        @endphp

                        <div class="rounded-box border border-base-300 bg-base-100 p-4">
                            <form method="POST" action="{{ route('admin.war-aid.approve', $req) }}" class="space-y-4">
                                @csrf
                                @method('PATCH')

                                <div class="flex flex-wrap items-start justify-between gap-4">
                                    <div class="flex min-w-0 items-start gap-3">
                                        @if ($nation)
                                            <img src="{{ $nation->flag }}" alt="Flag" class="h-8 w-12 rounded object-cover">
                                        @else
                                            <div class="h-8 w-12 rounded bg-base-300"></div>
                                        @endif

                                        <div class="min-w-0">
                                            <h3 class="font-semibold">
                                                @if ($nation)
                                                    <a href="https://politicsandwar.com/nation/id={{ $nation->id }}" target="_blank" rel="noopener noreferrer" class="link link-hover">
                                                        {{ $nation->leader_name ?? ('Nation #'.$nation->id) }}
                                                    </a>
                                                @else
                                                    Unknown Nation
                                                @endif
                                            </h3>
                                            @if ($nation)
                                                <div class="text-sm text-base-content/60">{{ $nation->nation_name ?? 'Unknown Nation' }}</div>
                                            @endif
                                            <div class="mt-1 text-sm text-base-content/60">
                                                Account:
                                                @if ($account)
                                                    <a href="{{ route('admin.accounts.view', $req->account_id) }}" class="link link-hover">{{ $account->name }}</a>
                                                @else
                                                    Unknown Account
                                                @endif
                                            </div>
                                            <div class="mt-1 text-sm text-base-content/60">Note: {{ $req->note }}</div>
                                        </div>
                                    </div>

                                    <div class="flex gap-2">
                                        <button type="submit" class="btn btn-success btn-sm">Approve</button>
                                    </div>
                                </div>

                                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                                    @foreach(PWHelperService::resources() as $resource)
                                        <label class="block space-y-2">
                                            <span class="text-sm font-medium">{{ ucfirst($resource) }}</span>
                                            <input
                                                type="number"
                                                name="{{ $resource }}"
                                                class="input input-bordered input-sm w-full"
                                                min="0"
                                                value="{{ $req->$resource }}"
                                            >
                                            <span class="text-xs text-base-content/60">
                                                Has: {{ number_format(($nation?->resources->$resource ?? 0) + ($nation?->accounts?->sum($resource) ?? 0)) }}
                                            </span>
                                        </label>
                                    @endforeach
                                </div>
                            </form>

                            <form method="POST" action="{{ route('admin.war-aid.deny', $req) }}" class="mt-4">
                                @csrf
                                @method('PATCH')
                                <button type="submit" class="btn btn-error btn-outline btn-sm">Deny</button>
                            </form>
                        </div>
                    @endforeach
                </div>
            </x-card>
        @endif

        @can('manage-war-aid')
            <x-card title="Manual War Aid Disbursement">
                <p class="mb-4 text-sm text-base-content/60">
                    Issues war aid immediately and bypasses pending-request checks. Provide at least one resource amount.
                </p>

                <form method="POST" action="{{ route('admin.manual-disbursements.war-aid') }}" class="space-y-4">
                    @csrf

                    <div class="grid gap-4 md:grid-cols-2">
                        <label class="block space-y-2">
                            <span class="text-sm font-medium">Nation ID</span>
                            <input type="number" name="nation_id" class="input input-bordered w-full" required min="1" value="{{ old('nation_id') }}">
                        </label>
                        <label class="block space-y-2">
                            <span class="text-sm font-medium">Account ID</span>
                            <input type="number" name="account_id" class="input input-bordered w-full" required min="1" value="{{ old('account_id') }}">
                            <span class="text-xs text-base-content/60">Must belong to the nation above.</span>
                        </label>
                    </div>

                    <label class="block space-y-2">
                        <span class="text-sm font-medium">Note (optional)</span>
                        <input
                            type="text"
                            name="note"
                            class="input input-bordered w-full"
                            maxlength="255"
                            value="{{ old('note') }}"
                            placeholder="Shown in request history"
                        >
                    </label>

                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        @foreach(PWHelperService::resources() as $resource)
                            <label class="block space-y-2">
                                <span class="text-sm font-medium text-capitalize">{{ $resource }}</span>
                                <input type="number" min="0" name="{{ $resource }}" class="input input-bordered w-full" value="{{ old($resource, 0) }}">
                            </label>
                        @endforeach
                    </div>

                    <div>
                        <button class="btn btn-primary" type="submit">Send War Aid</button>
                    </div>
                </form>
            </x-card>
        @endcan

        <x-card title="Past Requests">
            <div class="overflow-x-auto rounded-box border border-base-300">
                <table class="table table-zebra table-sm">
                    <thead>
                    <tr>
                        <th>Nation</th>
                        <th>Account</th>
                        <th>Note</th>
                        <th>Status</th>
                        <th>Money</th>
                        <th>Resources</th>
                        <th>Date</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($history as $req)
                        @php
                            $nation = $req->nation;
                            $account = $req->account;
                        @endphp
                        <tr>
                            <td>
                                @if ($nation)
                                    <a href="https://politicsandwar.com/nation/id={{ $nation->id }}" target="_blank" rel="noopener noreferrer" class="link link-hover">
                                        {{ $nation->leader_name ?? ('Nation #'.$nation->id) }}
                                    </a>
                                    <div class="text-sm text-base-content/60">{{ $nation->nation_name ?? 'Unknown Nation' }}</div>
                                @else
                                    <span class="text-base-content/60">Unknown Nation</span>
                                @endif
                            </td>
                            <td>
                                @if ($account)
                                    <a href="{{ route('admin.accounts.view', $req->account_id) }}" class="link link-hover">
                                        {{ $account->name }}
                                    </a>
                                @else
                                    <span class="text-base-content/60">Unknown Account</span>
                                @endif
                            </td>
                            <td>{{ $req->note }}</td>
                            <td>
                                <span class="badge {{ $req->status === 'approved' ? 'badge-success' : 'badge-error' }}">
                                    {{ ucfirst($req->status) }}
                                </span>
                            </td>
                            <td>{{ number_format($req->money) }}</td>
                            <td>
                                <div class="flex flex-wrap gap-2">
                                    @foreach(PWHelperService::resources(false, false, true) as $res)
                                        @if($req->$res > 0)
                                            <span class="badge badge-outline whitespace-normal break-words py-3 text-left">{{ ucfirst($res) }}: {{ $req->$res }}</span>
                                        @endif
                                    @endforeach
                                </div>
                            </td>
                            <td>{{ $req->created_at->format('M d, Y H:i') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-6 text-center text-base-content/60">No previous requests.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                {{ $history->links() }}
            </div>
        </x-card>
    </div>
@endsection
