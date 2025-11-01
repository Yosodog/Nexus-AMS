@extends('layouts.admin')

@section('content')
    @php use Illuminate\Support\Str; @endphp

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

            <div class="card-footer bg-white">
                {{ $users->links() }}
            </div>
        </div>
    </div>
@endsection

