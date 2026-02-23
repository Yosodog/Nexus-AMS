@extends('layouts.admin')

@section('content')
    @php use Illuminate\Support\Str; @endphp
    @php
        $filters = $filters ?? [
            'search' => '',
            'status' => 'enabled',
            'is_admin' => false,
            'alliance_member' => false,
            'verification' => 'any',
        ];
        $mfaRequirements = $mfaRequirements ?? [
            'all_users' => false,
            'admins' => false,
        ];
    @endphp

    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col">
                    <h3 class="mb-1">Users</h3>
                    <p class="text-muted mb-0">Monitor activity, spot administrators, and jump quickly into user management.</p>
                </div>
            </div>
        </div>
    </div>

    <div class="container-fluid">
        <div class="row g-3 mt-1">
            <div class="col-12 col-md-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <span class="text-uppercase text-muted small fw-semibold">Total users</span>
                                <div class="display-6 fw-bold mt-1">{{ $stats['total_users'] }}</div>
                            </div>
                            <span class="text-primary fs-3"><i class="bi bi-people"></i></span>
                        </div>
                        <p class="text-muted small mb-0 mt-3">All accounts that have access to {{ config("app.name") }}</p>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <span class="text-uppercase text-muted small fw-semibold">Admins</span>
                                <div class="display-6 fw-bold mt-1">{{ $stats['admins'] }}</div>
                            </div>
                            <span class="text-danger fs-3"><i class="bi bi-shield-check"></i></span>
                        </div>
                        <p class="text-muted small mb-0 mt-3">Users with elevated platform access.</p>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <span class="text-uppercase text-muted small fw-semibold">Active today</span>
                                <div class="display-6 fw-bold mt-1">{{ $stats['active_today'] }}</div>
                            </div>
                            <span class="text-success fs-3"><i class="bi bi-activity"></i></span>
                        </div>
                        <p class="text-muted small mb-0 mt-3">Members seen within the last 24 hours.</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mt-4">
            <div class="card-header d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-2">
                <div>
                    <h5 class="mb-0">Member directory</h5>
                    <span class="text-muted small">Browse user details and jump into edits without leaving the page.</span>
                </div>
                <a href="{{ route('admin.roles.index') }}" class="btn btn-outline-secondary">
                    <i class="bi bi-shield-lock me-1"></i>
                    Manage roles
                </a>
            </div>
            <div class="card-body border-top">
                <form method="GET" class="row g-3 align-items-end">
                    <div class="col-12 col-lg-4">
                        <label for="filter-search" class="form-label text-uppercase small fw-semibold text-muted">Search</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-search"></i></span>
                            <input
                                type="text"
                                id="filter-search"
                                name="search"
                                class="form-control"
                                placeholder="Username, Discord, or Nation ID"
                                value="{{ $filters['search'] }}"
                            >
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-lg-2">
                        <label for="filter-status" class="form-label text-uppercase small fw-semibold text-muted">Account status</label>
                        <select id="filter-status" name="status" class="form-select">
                            <option value="enabled" @selected($filters['status'] === 'enabled')>Enabled</option>
                            <option value="disabled" @selected($filters['status'] === 'disabled')>Disabled</option>
                            <option value="all" @selected($filters['status'] === 'all')>All accounts</option>
                        </select>
                    </div>
                    <div class="col-12 col-sm-6 col-lg-2">
                        <label for="filter-verification" class="form-label text-uppercase small fw-semibold text-muted">Verification</label>
                        <select id="filter-verification" name="verification" class="form-select">
                            <option value="any" @selected($filters['verification'] === 'any')>Any</option>
                            <option value="verified" @selected($filters['verification'] === 'verified')>Verified</option>
                            <option value="unverified" @selected($filters['verification'] === 'unverified')>Unverified</option>
                        </select>
                    </div>
                    <div class="col-6 col-lg-2">
                        <div class="form-check mt-4">
                            <input
                                class="form-check-input"
                                type="checkbox"
                                name="is_admin"
                                value="1"
                                id="filter-is-admin"
                                @checked($filters['is_admin'])
                            >
                            <label class="form-check-label small" for="filter-is-admin">
                                Admins only
                            </label>
                        </div>
                    </div>
                    <div class="col-6 col-lg-2">
                        <div class="form-check mt-4">
                            <input
                                class="form-check-input"
                                type="checkbox"
                                name="alliance_member"
                                value="1"
                                id="filter-alliance-member"
                                @checked($filters['alliance_member'])
                            >
                            <label class="form-check-label small" for="filter-alliance-member">
                                Alliance members
                            </label>
                        </div>
                    </div>
                    <div class="col-12 col-lg-12 d-grid gap-2 d-lg-flex justify-content-lg-end">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-funnel me-1"></i>
                            Apply filters
                        </button>
                        <a href="{{ route('admin.users.index') }}" class="btn btn-outline-secondary">
                            Reset
                        </a>
                    </div>
                </form>
                <div class="text-muted small mt-3">
                    Showing {{ $users->firstItem() ?? 0 }}-{{ $users->lastItem() ?? 0 }} of {{ $users->total() }} users
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                    <tr>
                        <th scope="col">User</th>
                        <th scope="col">Discord</th>
                        <th scope="col">Nation</th>
                        <th scope="col" class="text-center">Alliance</th>
                        <th scope="col">Roles</th>
                        <th scope="col">Last active</th>
                        <th scope="col" class="text-end">Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    @php $membershipService = app(\App\Services\AllianceMembershipService::class); @endphp
                    @foreach($users as $user)
                        <tr>
                            <td>
                                <div class="fw-semibold">{{ $user->name }}</div>
                                <div class="text-muted small">{{ $user->email }}</div>
                                <div class="d-flex flex-wrap gap-2 mt-2">
                                    @if($user->is_admin)
                                        <span class="badge bg-danger">Admin</span>
                                    @endif
                                    @if($user->disabled)
                                        <span class="badge bg-warning text-dark">Disabled</span>
                                    @endif
                                    @if(is_null($user->verified_at))
                                        <span class="badge bg-secondary">Unverified</span>
                                    @endif
                                    @if($user->last_active_at && now()->diffInMinutes($user->last_active_at) >= -5)
                                        <span class="badge bg-success">Online</span>
                                    @endif
                                </div>
                            </td>
                            <td>{{ $user->nation->discord ?? '—' }}</td>
                            <td>
                                @if($user->nation_id)
                                    <a href="https://politicsandwar.com/nation/id={{ $user->nation_id }}" target="_blank" class="text-decoration-none">
                                        {{ $user->nation_id }}
                                    </a>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="text-center">
                                @if($user->nation && $membershipService->contains($user->nation->alliance_id))
                                    <span class="badge bg-primary">Member</span>
                                @else
                                    <span class="badge bg-secondary">No</span>
                                @endif
                            </td>
                            <td>
                                <div class="d-flex flex-wrap gap-2">
                                    @forelse($user->roles as $role)
                                        <span class="badge rounded-pill bg-primary">{{ Str::title($role->name) }}</span>
                                    @empty
                                        <span class="text-muted">No roles assigned</span>
                                    @endforelse
                                </div>
                            </td>
                            <td>
                                {{ $user->last_active_at ? $user->last_active_at->diffForHumans() : 'Never' }}
                            </td>
                            <td class="text-end">
                                <a href="{{ route('admin.users.edit', $user->id) }}" class="btn btn-sm btn-primary">
                                    <i class="bi bi-pencil-square me-1"></i>
                                    Edit
                                </a>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            <div class="card-footer bg-white d-flex flex-column flex-lg-row justify-content-lg-between align-items-lg-center gap-2">
                <span class="text-muted small">Page {{ $users->currentPage() }} of {{ $users->lastPage() }}</span>
                {{ $users->onEachSide(1)->links() }}
            </div>
        </div>

        <div class="card border-0 shadow-sm mt-4">
            <div class="card-header">
                <h5 class="mb-1">Multi-factor authentication requirements</h5>
                <span class="text-muted small">Use these toggles to enforce Fortify two-factor authentication enrollment.</span>
            </div>
            <div class="card-body border-top">
                <form method="POST" action="{{ route('admin.users.mfa-requirements') }}" class="row g-3 align-items-end">
                    @csrf
                    <div class="col-12 col-lg-5">
                        <div class="form-check form-switch">
                            <input
                                class="form-check-input"
                                type="checkbox"
                                role="switch"
                                id="require-mfa-all-users"
                                name="require_mfa_all_users"
                                value="1"
                                @checked($mfaRequirements['all_users'])
                            >
                            <label class="form-check-label fw-semibold" for="require-mfa-all-users">
                                Require MFA for all users
                            </label>
                        </div>
                        <p class="text-muted small mb-0 mt-1">Default: off. Forces every authenticated user to enroll in MFA before using the app.</p>
                    </div>
                    <div class="col-12 col-lg-5">
                        <div class="form-check form-switch">
                            <input
                                class="form-check-input"
                                type="checkbox"
                                role="switch"
                                id="require-mfa-admins"
                                name="require_mfa_admins"
                                value="1"
                                @checked($mfaRequirements['admins'])
                            >
                            <label class="form-check-label fw-semibold" for="require-mfa-admins">
                                Require MFA for admins
                            </label>
                        </div>
                        <p class="text-muted small mb-0 mt-1">Default: off. Applies to administrator accounts even when the all-users toggle is off.</p>
                    </div>
                    <div class="col-12 col-lg-2 d-grid">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-shield-lock me-1"></i>
                            Save
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
