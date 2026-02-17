@extends('layouts.main')

@section('content')
    <div class="mx-auto space-y-6">
        <div class="rounded-2xl bg-base-100 border border-base-300 p-6 shadow">
            <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div>
                    <p class="text-xs uppercase tracking-wide text-base-content/60">Defense desk</p>
                    <h1 class="text-3xl font-bold leading-tight">Rebuilding</h1>
                    <p class="text-sm text-base-content/70">Apply for post-war infrastructure rebuilding assistance.</p>
                </div>
                <button
                    class="btn btn-primary"
                    @if ($enabled && $estimate['eligible'])
                        onclick="document.getElementById('rebuilding-request-modal').showModal()"
                    @else
                        disabled
                    @endif
                >
                    Apply for Rebuilding
                </button>
            </div>
        </div>

        <x-utils.card>
            <div class="grid gap-4 md:grid-cols-3">
                <div class="rounded-xl border border-base-300 p-4">
                    <p class="text-xs uppercase text-base-content/60">Cycle</p>
                    <p class="text-2xl font-semibold">{{ $cycleId }}</p>
                </div>
                <div class="rounded-xl border border-base-300 p-4">
                    <p class="text-xs uppercase text-base-content/60">Status</p>
                    <p class="text-2xl font-semibold">{{ $enabled ? 'Open' : 'Closed' }}</p>
                </div>
                <div class="rounded-xl border border-base-300 p-4">
                    <p class="text-xs uppercase text-base-content/60">Estimated Rebuild</p>
                    <p class="text-2xl font-semibold">${{ number_format((float) $estimate['amount']) }}</p>
                </div>
            </div>

            <div class="mt-4">
                @if (! $estimate['eligible'])
                    <div class="alert alert-warning">
                        <span>{{ $estimate['reason'] }}</span>
                    </div>
                @endif
            </div>

            <h2 class="text-xl font-bold mt-8 mb-2">Recent Rebuilding Requests</h2>
            <div class="overflow-x-auto">
                <table class="table w-full">
                    <thead>
                    <tr>
                        <th>Created</th>
                        <th>Status</th>
                        <th>Tier Infra</th>
                        <th>Estimated</th>
                        <th>Approved</th>
                        <th>Note</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($requests as $req)
                        <tr>
                            <td>{{ $req->created_at->format('M d, Y H:i') }}</td>
                            <td>
                                <span class="badge badge-{{ $req->status === 'approved' ? 'success' : ($req->status === 'denied' ? 'error' : ($req->status === 'expired' ? 'warning' : 'neutral')) }}">
                                    {{ ucfirst($req->status) }}
                                </span>
                            </td>
                            <td>{{ number_format((float) $req->target_infrastructure_snapshot, 2) }}</td>
                            <td>${{ number_format((float) $req->estimated_amount) }}</td>
                            <td>${{ number_format((float) ($req->approved_amount ?? 0)) }}</td>
                            <td>{{ $req->note ?: '-' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-gray-500">No rebuilding requests yet.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </x-utils.card>
    </div>

    @if ($enabled && $estimate['eligible'])
        <dialog id="rebuilding-request-modal" class="modal">
            <div class="modal-box w-11/12 max-w-2xl">
                <form method="POST" action="{{ route('defense.rebuilding.store') }}" class="space-y-4">
                    @csrf
                    <h3 class="font-bold text-lg">Apply for Rebuilding</h3>

                    <div class="form-control">
                        <label class="label">Send funds to account</label>
                        <select class="select select-bordered" name="account_id" required>
                            @foreach($nation->accounts as $account)
                                <option value="{{ $account->id }}">{{ $account->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="form-control">
                        <label class="label">Optional note</label>
                        <textarea class="textarea textarea-bordered" name="note" maxlength="255" placeholder="Any context for admins"></textarea>
                    </div>

                    <div class="rounded-xl border border-base-300 p-3 text-sm">
                        <p><strong>City count:</strong> {{ $estimate['city_count'] }}</p>
                        <p><strong>Tier target infra:</strong> {{ number_format((float) ($estimate['tier']->target_infrastructure ?? 0), 2) }}</p>
                        <p><strong>Estimated payout:</strong> ${{ number_format((float) $estimate['amount']) }}</p>
                    </div>

                    <div class="modal-action">
                        <button type="submit" class="btn btn-primary">Submit</button>
                        <button type="button" class="btn" onclick="document.getElementById('rebuilding-request-modal').close()">Cancel</button>
                    </div>
                </form>
            </div>
        </dialog>
    @endif
@endsection
