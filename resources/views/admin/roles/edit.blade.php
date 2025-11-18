@extends('layouts.admin')

@section('content')
    @php use Illuminate\Support\Str; @endphp

    <div class="app-content-header">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                <div>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <h3 class="mb-1">Edit Role: {{ Str::headline($role->name) }}</h3>
                    </div>
                    <p class="text-muted mb-0">Update the name or refine the permissions assigned to this role.</p>
                </div>
                <a href="{{ route('admin.roles.index') }}" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left-circle me-1"></i> Back to roles
                </a>
            </div>
        </div>
    </div>

    <form method="POST" action="{{ route('admin.roles.update', $role) }}" class="mt-3">
        @csrf
        @method('PUT')

        <div class="row g-3">
            <div class="col-12 col-xl-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <h5 class="mb-1">Role details</h5>
                        <p class="text-muted small mb-4">Keep names purposeful so teammates know when to use this role.</p>

                        @if($role->protected)
                            <div class="alert alert-warning d-flex align-items-start gap-2 mb-3">
                                <i class="bi bi-shield-lock-fill fs-5"></i>
                                <div>
                                    <div class="fw-semibold">Protected role</div>
                                    <div class="small mb-0">This role is locked to prevent accidental edits. Permissions are read-only.</div>
                                </div>
                            </div>
                        @endif

                        <div class="mb-3">
                            <label class="form-label" for="role-name">Role name</label>
                            <input id="role-name"
                                   type="text"
                                   name="name"
                                   value="{{ old('name', $role->name) }}"
                                   class="form-control @error('name') is-invalid @enderror"
                                   placeholder="e.g. finance.reviewer"
                                    {{ $role->protected ? 'readonly disabled' : '' }}>
                            @error('name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @else
                                <div class="form-text">Names appear in audit logs and member management.</div>
                            @enderror
                        </div>

                        <div class="bg-body-secondary rounded p-3 border small">
                            <div class="fw-semibold mb-1"><i class="bi bi-clipboard-check me-1"></i>Checklist</div>
                            <ul class="mb-0 ps-3">
                                <li>Keep responsibilities narrow.</li>
                                <li>Only enable permissions that are truly required.</li>
                                <li>Review membership after changing permissions.</li>
                            </ul>
                        </div>

                        <div class="mt-4">
                            @php
                                $maxUsersToShow = 12;
                                $totalUsers = $role->users->count();
                                $visibleUsers = $role->users->take($maxUsersToShow);
                            @endphp
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <div>
                                    <div class="fw-semibold">Assigned members</div>
                                    <div class="text-muted small">
                                        {{ $totalUsers ? 'People currently using this role.' : 'No members have this role yet.' }}
                                    </div>
                                </div>
                                <span class="badge bg-body-secondary border text-muted">
                                    <i class="bi bi-people me-1"></i>{{ $totalUsers }}
                                </span>
                            </div>
                            <div class="d-flex flex-wrap gap-2">
                                @forelse($visibleUsers as $user)
                                    @php
                                        $flag = $user->nation?->flag;
                                        $nationName = $user->nation?->nation_name;
                                        $initials = collect(explode(' ', $user->name))
                                            ->filter()
                                            ->map(fn ($part) => Str::upper(Str::substr($part, 0, 1)))
                                            ->take(2)
                                            ->implode('');
                                    @endphp
                                    <a href="{{ route('admin.users.edit', $user) }}" class="text-decoration-none">
                                        <div class="d-flex align-items-center gap-2 border rounded-pill px-2 py-1 bg-body-secondary-subtle">
                                            @if($flag)
                                                <img src="{{ $flag }}" alt="{{ $user->name }} flag" class="rounded-circle border" style="width: 36px; height: 36px; object-fit: cover;">
                                            @else
                                                <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-primary-subtle text-primary fw-semibold" style="width: 36px; height: 36px;">
                                                    {{ $initials ?: '?' }}
                                                </div>
                                            @endif
                                            <div class="d-flex flex-column">
                                                <span class="fw-semibold small text-body">{{ $user->name }}</span>
                                                <span class="text-muted small">{{ $nationName ?: 'No nation linked' }}</span>
                                            </div>
                                        </div>
                                    </a>
                                @empty
                                    <div class="text-muted small d-flex align-items-center gap-2">
                                        <i class="bi bi-people"></i>
                                        <span>No members have this role yet.</span>
                                    </div>
                                @endforelse

                                @if($totalUsers > $maxUsersToShow)
                                    <span class="badge bg-body-secondary border text-muted">
                                        +{{ $totalUsers - $maxUsersToShow }} more
                                    </span>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-xl-8">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div>
                            <h5 class="mb-0">Permissions</h5>
                            <span class="text-muted small">Enable the capabilities this role should have.</span>
                        </div>
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <div class="input-group input-group-sm" style="max-width: 280px;">
                                <span class="input-group-text bg-body"><i class="bi bi-search"></i></span>
                                <input type="search" class="form-control" placeholder="Filter permissions" data-permission-search {{ $role->protected ? 'disabled' : '' }}>
                            </div>
                            <div class="btn-group btn-group-sm" role="group" aria-label="Permission quick actions">
                                <button class="btn btn-outline-primary" type="button" data-permission-select="all" {{ $role->protected ? 'disabled' : '' }}>
                                    <i class="bi bi-check2-square me-1"></i> Select all
                                </button>
                                <button class="btn btn-outline-secondary" type="button" data-permission-select="none" {{ $role->protected ? 'disabled' : '' }}>
                                    <i class="bi bi-x-square me-1"></i> Clear
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        @php $selectedPermissions = old('permissions', $role->permissions->pluck('permission')->toArray()); @endphp
                        <div class="row g-2" id="permission-grid">
                            @foreach($permissions as $perm)
                                @php
                                    $isChecked = in_array($perm, $selectedPermissions);
                                    $isView = Str::startsWith($perm, 'view');
                                    $typeLabel = $isView ? 'View' : 'Manage';
                                    $typeClass = $isView ? 'bg-info-subtle text-info-emphasis border-info-subtle' : 'bg-primary-subtle text-primary-emphasis border-primary-subtle';
                                    $typeIcon = $isView ? 'bi-eye' : 'bi-gear';
                                    $description = $isView ? 'Read-only access to ' . Str::headline($perm) : 'Full management access to ' . Str::headline($perm) . ' features.';
                                @endphp
                                <div class="col-12 col-md-6 col-lg-4 permission-item" data-permission-label="{{ Str::lower($perm) }}">
                                    <div class="d-flex align-items-start gap-3 border rounded p-3 h-100 bg-body-secondary-subtle {{ $role->protected ? 'opacity-75' : '' }}">
                                        <input type="checkbox"
                                               name="permissions[]"
                                               value="{{ $perm }}"
                                               class="form-check-input position-relative mt-1 flex-shrink-0"
                                               style="margin-left: 0; margin-right: 0.25rem;"
                                               id="perm-{{ Str::slug($perm) }}"
                                                {{ $isChecked ? 'checked' : '' }}
                                                {{ $role->protected ? 'disabled' : '' }}>
                                        <label for="perm-{{ Str::slug($perm) }}" class="form-check-label w-100">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <span class="fw-semibold">{{ Str::headline($perm) }}</span>
                                                <span class="badge border {{ $typeClass }}">
                                                    <i class="bi {{ $typeIcon }} me-1"></i>{{ $typeLabel }}
                                                </span>
                                            </div>
                                            <span class="text-muted small d-block mt-1">{{ $description }}</span>
                                        </label>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        <div class="text-muted small mt-3">
                            <i class="bi bi-info-circle me-1"></i>Changes apply as soon as you save the role.
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex justify-content-end gap-2 mt-3">
            <a href="{{ route('admin.roles.index') }}" class="btn btn-outline-secondary">Back</a>
            @if(!$role->protected)
                <button type="submit" class="btn btn-success">
                    <i class="bi bi-save me-1"></i> Save changes
                </button>
            @endif
        </div>
    </form>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const searchInput = document.querySelector('[data-permission-search]');
            const items = Array.from(document.querySelectorAll('[data-permission-label]'));
            const selectButtons = document.querySelectorAll('[data-permission-select]');

            const permissionCheckboxes = () => Array.from(document.querySelectorAll('[data-permission-label] input[type="checkbox"]'));

            if (searchInput) {
                searchInput.addEventListener('input', function (event) {
                    const term = event.target.value.toLowerCase().trim();

                    items.forEach((item) => {
                        const label = item.getAttribute('data-permission-label') || '';
                        item.classList.toggle('d-none', term && !label.includes(term));
                    });
                });
            }

            selectButtons.forEach((button) => {
                button.addEventListener('click', () => {
                    const mode = button.getAttribute('data-permission-select');

                    permissionCheckboxes().forEach((checkbox) => {
                        if (checkbox.disabled) {
                            return;
                        }

                        checkbox.checked = mode === 'all';
                    });
                });
            });
        });
    </script>
@endpush
