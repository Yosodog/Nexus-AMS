@extends('layouts.admin')

@section('content')
    @php use Illuminate\Support\Str; @endphp

    <x-header :title="'Edit Role: ' . Str::headline($role->name)" separator>
        <x-slot:subtitle>Update the role name, review assigned members, and tune permissions without leaving the page.</x-slot:subtitle>
        <x-slot:actions>
            <a href="{{ route('admin.roles.index') }}" class="btn btn-ghost btn-sm">Back to roles</a>
        </x-slot:actions>
    </x-header>

    <form method="POST" action="{{ route('admin.roles.update', $role) }}">
        @csrf
        @method('PUT')

        <div class="grid gap-6 xl:grid-cols-[360px_minmax(0,1fr)]">
            <x-card>
                <x-slot:title>
                    <div>
                        Role Details
                        <div class="text-sm font-normal text-base-content/60">Keep names purposeful so teammates understand when to use this role.</div>
                    </div>
                </x-slot:title>

                <div class="space-y-4">
                    @if($role->protected)
                        <x-alert class="alert-warning" icon="o-lock-closed">
                            This role is protected. Its name and permissions are read-only.
                        </x-alert>
                    @endif

                    <x-input label="Role Name"
                             id="role-name"
                             name="name"
                             :value="old('name', $role->name)"
                             placeholder="finance.reviewer"
                             :readonly="$role->protected"
                             :disabled="$role->protected" />

                    <div class="rounded-2xl bg-base-200/70 p-4 text-sm text-base-content/75">
                        <div class="font-semibold text-base-content">Checklist</div>
                        <ul class="mt-2 space-y-2">
                            <li>Keep responsibilities narrow.</li>
                            <li>Only enable permissions that are truly required.</li>
                            <li>Review membership after saving permission changes.</li>
                        </ul>
                    </div>

                    @php
                        $maxUsersToShow = 12;
                        $totalUsers = $role->users->count();
                        $visibleUsers = $role->users->take($maxUsersToShow);
                    @endphp

                    <div class="space-y-3">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <div class="font-semibold text-base-content">Assigned Members</div>
                                <div class="text-sm text-base-content/60">{{ $totalUsers ? 'People currently using this role.' : 'No members have this role yet.' }}</div>
                            </div>
                            <x-badge :value="(string) $totalUsers" class="badge-ghost badge-sm" />
                        </div>

                        <div class="grid gap-2">
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
                                <a href="{{ route('admin.users.edit', $user) }}" class="flex items-center gap-3 rounded-2xl border border-base-300 bg-base-100 px-3 py-2 transition hover:border-primary/30 hover:bg-primary/5">
                                    @if($flag)
                                        <img src="{{ $flag }}" alt="{{ $user->name }} flag" class="h-10 w-10 rounded-full border border-base-300 object-cover">
                                    @else
                                        <div class="grid h-10 w-10 place-items-center rounded-full bg-primary/15 font-semibold text-primary">{{ $initials ?: '?' }}</div>
                                    @endif
                                    <div class="min-w-0">
                                        <div class="truncate font-semibold text-base-content">{{ $user->name }}</div>
                                        <div class="truncate text-sm text-base-content/60">{{ $nationName ?: 'No nation linked' }}</div>
                                    </div>
                                </a>
                            @empty
                                <div class="rounded-2xl border border-dashed border-base-300 px-4 py-5 text-sm text-base-content/60">
                                    No members have this role yet.
                                </div>
                            @endforelse
                        </div>

                        @if($totalUsers > $maxUsersToShow)
                            <x-badge :value="'+' . ($totalUsers - $maxUsersToShow) . ' more'" class="badge-ghost badge-sm" />
                        @endif
                    </div>
                </div>
            </x-card>

            <x-card>
                <x-slot:title>
                    <div>
                        Permissions
                        <div class="text-sm font-normal text-base-content/60">Enable only the capabilities this role should have.</div>
                    </div>
                </x-slot:title>
                <x-slot:menu>
                    <div class="flex flex-wrap items-center gap-2">
                        <div class="join">
                            <button class="btn btn-outline btn-primary btn-sm join-item" type="button" data-permission-select="all" {{ $role->protected ? 'disabled' : '' }}>Select all</button>
                            <button class="btn btn-ghost btn-sm join-item" type="button" data-permission-select="none" {{ $role->protected ? 'disabled' : '' }}>Clear</button>
                        </div>
                        <x-input placeholder="Filter permissions" icon="o-magnifying-glass" data-permission-search :disabled="$role->protected" class="input-sm w-56" />
                    </div>
                </x-slot:menu>

                @php $selectedPermissions = old('permissions', $role->permissions->pluck('permission')->toArray()); @endphp
                <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3" id="permission-grid">
                    @foreach($permissions as $perm)
                        @php
                            $isChecked = in_array($perm, $selectedPermissions);
                            $isView = Str::startsWith($perm, 'view');
                            $typeLabel = $isView ? 'View' : 'Manage';
                            $typeClass = $isView ? 'badge-info badge-outline' : 'badge-primary badge-outline';
                            $description = $isView ? 'Read-only access to ' . Str::headline($perm) : 'Full management access to ' . Str::headline($perm) . ' features.';
                        @endphp
                        <label class="permission-item flex cursor-pointer items-start gap-3 rounded-2xl border border-base-300 bg-base-100 px-4 py-4 transition hover:border-primary/30 hover:bg-primary/5 {{ $role->protected ? 'opacity-75' : '' }}"
                               data-permission-label="{{ Str::lower($perm) }}">
                            <input type="checkbox"
                                   name="permissions[]"
                                   value="{{ $perm }}"
                                   class="checkbox checkbox-primary mt-1"
                                   id="perm-{{ Str::slug($perm) }}"
                                   {{ $isChecked ? 'checked' : '' }}
                                   {{ $role->protected ? 'disabled' : '' }}>
                            <span class="min-w-0 flex-1">
                                <span class="flex flex-wrap items-center justify-between gap-2">
                                    <span class="font-semibold text-base-content">{{ Str::headline($perm) }}</span>
                                    <x-badge :value="$typeLabel" class="{{ $typeClass }} badge-sm" />
                                </span>
                                <span class="mt-1 block text-sm text-base-content/60">{{ $description }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>

                <div class="mt-4 flex justify-end gap-2 border-t border-base-300 pt-4">
                    <a href="{{ route('admin.roles.index') }}" class="btn btn-ghost">Back</a>
                    @if(! $role->protected)
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    @endif
                </div>
            </x-card>
        </div>
    </form>
@endsection

@push('scripts')
    <script>
        function initRoleEditPage() {
            const searchInput = document.querySelector('[data-permission-search]');
            const items = Array.from(document.querySelectorAll('[data-permission-label]'));
            const selectButtons = document.querySelectorAll('[data-permission-select]');

            const permissionCheckboxes = () => Array.from(document.querySelectorAll('[data-permission-label] input[type="checkbox"]'));

            if (searchInput) {
                searchInput.addEventListener('input', function (event) {
                    const term = event.target.value.toLowerCase().trim();

                    items.forEach((item) => {
                        const label = item.getAttribute('data-permission-label') || '';
                        item.classList.toggle('hidden', term && !label.includes(term));
                    });
                });
            }

            selectButtons.forEach((button) => {
                if (button.dataset.bound === 'true') {
                    return;
                }

                button.dataset.bound = 'true';
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
        }

        document.addEventListener('codex:page-ready', initRoleEditPage);
        initRoleEditPage();
    </script>
@endpush
