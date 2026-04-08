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
        <div class="w-full">

            {{-- Pending Requests --}}
            @if ($pending->isNotEmpty())
                <div class="card mb-4">
                    <div class="card-header">
                        <h3 class="card-title">Pending Requests</h3>
                    </div>
                    <div class="card-body">
                        <div class="space-y-4">
                        @foreach ($pending as $req)
                            @php
                                $nation = $req->nation;
                                $account = $req->account;
                            @endphp
                            <div class="rounded-2xl border border-base-300 bg-base-200/50 p-4">

                                    {{-- Approve Form --}}
                                    <form method="POST" action="{{ route('admin.war-aid.approve', $req) }}">
                                        @csrf
                                        @method('PATCH')

                                        <div class="flex justify-content-between align-items-start mb-3 flex-wrap gap-3">
                                            <div class="flex align-items-center gap-3">
                                                @if ($nation)
                                                    <img src="{{ $nation->flag }}" alt="Flag" style="height: 30px;">
                                                @else
                                                    <div class="bg-secondary rounded" style="height: 30px; width: 45px;"></div>
                                                @endif
                                                <div>
                                                    <h5 class="mb-1">
                                                        @if ($nation)
                                                            <a href="https://politicsandwar.com/nation/id={{ $nation->id }}"
                                                               target="_blank" rel="noopener noreferrer">
                                                                {{ $nation->leader_name ?? ('Nation #'.$nation->id) }}
                                                            </a>
                                                        @else
                                                            Unknown Nation
                                                        @endif
                                                    </h5>
                                                    @if ($nation)
                                                        <div class="small text-base-content/50 mb-1">
                                                            {{ $nation->nation_name ?? 'Unknown Nation' }}
                                                        </div>
                                                    @endif
                                                    <small class="text-base-content/50">Account:
                                                        @if ($account)
                                                            <a href="{{ route('admin.accounts.view', $req->account_id) }}">
                                                                {{ $account->name }}
                                                            </a>
                                                        @else
                                                            <span>Unknown Account</span>
                                                        @endif
                                                    </small><br>
                                                    <small class="text-base-content/50">Note: {{ $req->note }}</small>
                                                </div>
                                            </div>
                                            <div class="flex gap-2">
                                                <button type="submit" class="btn btn-success btn-sm">Approve</button>
                                            </div>
                                        </div>

                                        <div class="row g-2">
                                            @foreach(PWHelperService::resources() as $resource)
                                                <div class="col-md-3 col-sm-4 col-6">
                                                    <label class="form-label">{{ ucfirst($resource) }}</label>
                                                    <input type="number" name="{{ $resource }}"
                                                           class="form-control form-control-sm"
                                                           min="0" value="{{ $req->$resource }}">
                                                    <small class="text-base-content/50">
                                                        Has: {{ number_format(($nation?->resources->$resource ?? 0) + ($nation?->accounts?->sum($resource) ?? 0)) }}
                                                    </small>
                                                </div>
                                            @endforeach
                                        </div>
                                    </form>

                                    {{-- Deny Form - OUTSIDE the approve form --}}
                                    <form method="POST" action="{{ route('admin.war-aid.deny', $req) }}" class="mt-3">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="btn btn-danger btn-sm">Deny</button>
                                    </form>

                            </div>
                        @endforeach
                        </div>
                    </div>
                </div>
            @endif

            @can('manage-war-aid')
                <div class="card mb-4">
                    <div class="card-header">Manual War Aid Disbursement</div>
                    <div class="card-body">
                        <p class="text-base-content/50 small mb-3">
                            Issues war aid immediately and bypasses pending-request checks. Provide at least one resource amount.
                        </p>
                        <form method="POST" action="{{ route('admin.manual-disbursements.war-aid') }}">
                            @csrf
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Nation ID</label>
                                    <input type="number" name="nation_id" class="form-control" required min="1" value="{{ old('nation_id') }}">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Account ID</label>
                                    <input type="number" name="account_id" class="form-control" required min="1" value="{{ old('account_id') }}">
                                    <small class="text-base-content/50">Must belong to the nation above.</small>
                                </div>
                            </div>
                            <div class="mt-3">
                                <label class="form-label">Note (optional)</label>
                                <input type="text" name="note" class="form-control" maxlength="255" value="{{ old('note') }}"
                                       placeholder="Shown in request history">
                            </div>
                            <div class="row g-2 mt-2">
                                @foreach(PWHelperService::resources() as $resource)
                                    <div class="col-sm-6 col-md-4 col-lg-3">
                                        <label class="form-label text-capitalize">{{ $resource }}</label>
                                        <input type="number" min="0" name="{{ $resource }}" class="form-control"
                                               value="{{ old($resource, 0) }}">
                                    </div>
                                @endforeach
                            </div>
                            <div class="flex justify-content-end mt-3">
                                <button class="btn btn-primary" type="submit">Send War Aid</button>
                            </div>
                        </form>
                    </div>
                </div>
            @endcan

            {{-- Past Requests --}}
            <div class="card mb-4">
                <div class="card-header">
                    <h3 class="card-title">Past Requests</h3>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover table-sm align-middle">
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
                                            <a href="https://politicsandwar.com/nation/id={{ $nation->id }}"
                                               target="_blank" rel="noopener noreferrer">
                                                {{ $nation->leader_name ?? ('Nation #'.$nation->id) }}
                                            </a>
                                            <div class="small text-base-content/50">{{ $nation->nation_name ?? 'Unknown Nation' }}</div>
                                        @else
                                            <span class="text-base-content/50">Unknown Nation</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if ($account)
                                            <a href="{{ route('admin.accounts.view', $req->account_id) }}">
                                                {{ $account->name }}
                                            </a>
                                        @else
                                            <span class="text-base-content/50">Unknown Account</span>
                                        @endif
                                    </td>
                                    <td>{{ $req->note }}</td>
                                    <td>
                                            <span class="badge bg-{{ $req->status === 'approved' ? 'success' : 'danger' }}">
                                                {{ ucfirst($req->status) }}
                                            </span>
                                    </td>
                                    <td>{{ number_format($req->money) }}</td>
                                    <td>
                                        @foreach(PWHelperService::resources(false, false, true) as $res)
                                            @if($req->$res > 0)
                                                <span class="badge bg-secondary">{{ ucfirst($res) }}: {{ $req->$res }}</span>
                                            @endif
                                        @endforeach
                                    </td>
                                    <td>{{ $req->created_at->format('M d, Y H:i') }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="text-center text-base-content/50">No previous requests.</td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-3">
                        {{ $history->links() }}
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
