@extends('layouts.admin')

@section('content')
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row">
                <div class="col-sm-6"><h3 class="mb-0">War Aid Management</h3></div>
                <div class="col-sm-6 text-end">
                    <form method="POST" action="{{ route('admin.war-aid.toggle') }}">
                        @csrf
                        <button class="btn btn-{{ $enabled ? 'warning' : 'success' }}">
                            {{ $enabled ? 'Disable War Aid' : 'Enable War Aid' }}
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="app-content">
        <div class="container-fluid">

            {{-- Pending Requests --}}
            @if ($pending->isNotEmpty())
                <div class="card mb-4">
                    <div class="card-header">
                        <h3 class="card-title">Pending Requests</h3>
                    </div>
                    <div class="card-body">
                        @foreach ($pending as $req)
                            <div class="card mb-4 shadow-sm border">
                                <div class="card-body">

                                    {{-- Approve Form --}}
                                    <form method="POST" action="{{ route('admin.war-aid.approve', $req) }}">
                                        @csrf
                                        @method('PATCH')

                                        <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-3">
                                            <div class="d-flex align-items-center gap-3">
                                                <img src="{{ $req->nation->flag }}" alt="Flag" style="height: 30px;">
                                                <div>
                                                    <h5 class="mb-1">{{ $req->nation->leader_name }}</h5>
                                                    <small class="text-muted">Account:
                                                        <a href="{{ route('admin.accounts.view', $req->account_id) }}">
                                                            {{ $req->account->name }}
                                                        </a>
                                                    </small><br>
                                                    <small class="text-muted">Note: {{ $req->note }}</small>
                                                </div>
                                            </div>
                                            <div class="d-flex gap-2">
                                                <button type="submit" class="btn btn-success btn-sm">Approve</button>
                                            </div>
                                        </div>

                                        <div class="row g-2">
                                            @foreach(\App\Services\PWHelperService::resources() as $resource)
                                                <div class="col-md-3 col-sm-4 col-6">
                                                    <label class="form-label">{{ ucfirst($resource) }}</label>
                                                    <input type="number" name="{{ $resource }}" class="form-control form-control-sm"
                                                           min="0" value="{{ $req->$resource }}">
                                                    <small class="text-muted">
                                                        Has: {{ number_format(($req->nation->resources->$resource ?? 0) + $req->nation->accounts->sum($resource)) }}
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
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

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
                                <tr>
                                    <td>
                                        <a href="https://politicsandwar.com/nation/id={{ $req->nation->id }}" target="_blank">
                                            {{ $req->nation->leader_name }}
                                        </a>
                                    </td>
                                    <td>
                                        <a href="{{ route('admin.accounts.view', $req->account_id) }}">
                                            {{ $req->account->name }}
                                        </a>
                                    </td>
                                    <td>{{ $req->note }}</td>
                                    <td>
                                            <span class="badge bg-{{ $req->status === 'approved' ? 'success' : 'danger' }}">
                                                {{ ucfirst($req->status) }}
                                            </span>
                                    </td>
                                    <td>{{ number_format($req->money) }}</td>
                                    <td>
                                        @foreach(\App\Services\PWHelperService::resources(false, false, true) as $res)
                                            @if($req->$res > 0)
                                                <span class="badge bg-secondary">{{ ucfirst($res) }}: {{ $req->$res }}</span>
                                            @endif
                                        @endforeach
                                    </td>
                                    <td>{{ $req->created_at->format('M d, Y H:i') }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="text-center text-muted">No previous requests.</td></tr>
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