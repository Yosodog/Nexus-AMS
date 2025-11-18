@extends('layouts.admin')

@section('content')
    @php use Illuminate\Support\Str; @endphp

    <div class="app-content-header">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                <div>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <h3 class="mb-1">Create Role</h3>
                        <span class="badge rounded-pill text-bg-success-subtle border border-success-subtle">
                            <i class="bi bi-tools me-1"></i>Manage mode
                        </span>
                    </div>
                    <p class="text-muted mb-0">Give the role a clear name and choose the permissions that match its purpose.</p>
                </div>
                <a href="{{ route('admin.roles.index') }}" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left-circle me-1"></i> Back to roles
                </a>
            </div>
        </div>
    </div>

    <form method="POST" action="{{ route('admin.roles.store') }}" class="mt-3">
        @csrf

        <div class="row g-3">
            <div class="col-12 col-xl-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <h5 class="mb-1">Role details</h5>
                        <p class="text-muted small mb-4">A concise name makes it easy to spot in member management and audit logs.</p>

                        <div class="mb-3">
                            <label class="form-label" for="role-name">Role name</label>
                            <input id="role-name"
                                   type="text"
                                   name="name"
                                   value="{{ old('name') }}"
                                   class="form-control @error('name') is-invalid @enderror"
                                   placeholder="e.g. finance.reviewer"
                                   required>
                            @error('name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @else
                                <div class="form-text">Use a descriptive, lowercase name so others know what this role is for.</div>
                            @enderror
                        </div>

                        <div class="bg-body-secondary rounded p-3 border small">
                            <div class="fw-semibold mb-1"><i class="bi bi-lightbulb me-1"></i> Tips</div>
                            <ul class="mb-0 ps-3">
                                <li>Match one role to one responsibility.</li>
                                <li>Favor adding permissions as needed instead of starting with too many.</li>
                                <li>Review permissions regularly to keep access tidy.</li>
                            </ul>
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
                                <input type="search" class="form-control" placeholder="Filter permissions" data-permission-search>
                            </div>
                            <div class="btn-group btn-group-sm" role="group" aria-label="Permission quick actions">
                                <button class="btn btn-outline-primary" type="button" data-permission-select="all">
                                    <i class="bi bi-check2-square me-1"></i> Select all
                                </button>
                                <button class="btn btn-outline-secondary" type="button" data-permission-select="none">
                                    <i class="bi bi-x-square me-1"></i> Clear
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="row g-2" id="permission-grid">
                            @foreach($permissions as $perm)
                                @php
                                    $isChecked = in_array($perm, old('permissions', []));
                                    $isView = Str::startsWith($perm, 'view');
                                    $typeLabel = $isView ? 'View' : 'Manage';
                                    $typeClass = $isView ? 'bg-info-subtle text-info-emphasis border-info-subtle' : 'bg-primary-subtle text-primary-emphasis border-primary-subtle';
                                    $typeIcon = $isView ? 'bi-eye' : 'bi-gear';
                                    $description = $isView ? 'Read-only access to ' . Str::headline($perm) : 'Full management access to ' . Str::headline($perm) . ' features.';
                                @endphp
                                <div class="col-12 col-md-6 col-lg-4 permission-item" data-permission-label="{{ Str::lower($perm) }}">
                                    <div class="d-flex align-items-start gap-3 border rounded p-3 h-100 bg-body-secondary-subtle">
                                        <input type="checkbox"
                                               name="permissions[]"
                                               value="{{ $perm }}"
                                               class="form-check-input position-relative mt-1 flex-shrink-0"
                                               style="margin-left: 0; margin-right: 0.25rem;"
                                               id="perm-{{ Str::slug($perm) }}"
                                                {{ $isChecked ? 'checked' : '' }}>
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
                            <i class="bi bi-info-circle me-1"></i>Permissions update immediately after saving.
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex justify-content-end gap-2 mt-3">
            <a href="{{ route('admin.roles.index') }}" class="btn btn-outline-secondary">Cancel</a>
            <button type="submit" class="btn btn-success">
                <i class="bi bi-save me-1"></i> Create role
            </button>
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
