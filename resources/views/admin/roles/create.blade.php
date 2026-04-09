@extends('layouts.admin')

@section('content')
    @php use Illuminate\Support\Str; @endphp

    <x-header title="Create Role" separator>
        <x-slot:subtitle>Give the role a clear name and choose the permissions that match its purpose.</x-slot:subtitle>
        <x-slot:actions>
            <span class="badge badge-success badge-soft">
                <x-icon name="o-wrench-screwdriver" class="size-4" />
                Manage mode
            </span>
            <a href="{{ route('admin.roles.index') }}" class="btn btn-outline">
                <x-icon name="o-arrow-left-circle" class="size-4" />
                Back to roles
            </a>
        </x-slot:actions>
    </x-header>

    <form method="POST" action="{{ route('admin.roles.store') }}" class="mt-3">
        @csrf

        <div class="grid gap-6 xl:grid-cols-[minmax(320px,1fr)_minmax(0,2fr)]">
            <x-card title="Role details">
                <p class="mb-4 text-sm text-base-content/60">A concise name makes it easy to spot in member management and audit logs.</p>

                <x-input
                    id="role-name"
                    label="Role name"
                    name="name"
                    :value="old('name')"
                    error-field="name"
                    hint="Use a descriptive, lowercase name so others know what this role is for."
                    placeholder="e.g. finance.reviewer"
                    required
                />

                <div class="mt-5 rounded-box border border-base-300 bg-base-200/40 p-4 text-sm">
                    <div class="mb-2 flex items-center gap-2 font-semibold">
                        <x-icon name="o-light-bulb" class="size-4" />
                        Tips
                    </div>
                    <ul class="list-disc space-y-1 pl-5">
                        <li>Match one role to one responsibility.</li>
                        <li>Favor adding permissions as needed instead of starting with too many.</li>
                        <li>Review permissions regularly to keep access tidy.</li>
                    </ul>
                </div>
            </x-card>

            <x-card title="Permissions" subtitle="Enable the capabilities this role should have.">
                <x-slot:menu>
                    <div class="flex flex-wrap items-center gap-2">
                        <label class="input input-sm w-full max-w-xs">
                            <x-icon name="o-magnifying-glass" class="size-4" />
                            <input type="search" placeholder="Filter permissions" data-permission-search />
                        </label>
                        <button class="btn btn-outline btn-primary btn-sm" type="button" data-permission-select="all">
                            <x-icon name="o-check-badge" class="size-4" />
                            Select all
                        </button>
                        <button class="btn btn-outline btn-sm" type="button" data-permission-select="none">
                            <x-icon name="o-x-mark" class="size-4" />
                            Clear
                        </button>
                    </div>
                </x-slot:menu>

                <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3" id="permission-grid">
                    @foreach($permissions as $perm)
                        @php
                            $isChecked = in_array($perm, old('permissions', []));
                            $isView = Str::startsWith($perm, 'view');
                            $typeLabel = $isView ? 'View' : 'Manage';
                            $typeClass = $isView ? 'badge-info badge-soft' : 'badge-primary badge-soft';
                            $description = $isView ? 'Read-only access to ' . Str::headline($perm) : 'Full management access to ' . Str::headline($perm) . ' features.';
                        @endphp
                        <label class="permission-item flex gap-3 rounded-box border border-base-300 bg-base-200/30 p-4" data-permission-label="{{ Str::lower($perm) }}" for="perm-{{ Str::slug($perm) }}">
                            <input
                                type="checkbox"
                                name="permissions[]"
                                value="{{ $perm }}"
                                class="checkbox checkbox-sm mt-1 shrink-0"
                                id="perm-{{ Str::slug($perm) }}"
                                @checked($isChecked)
                            >
                            <div class="min-w-0">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="font-semibold">{{ Str::headline($perm) }}</span>
                                    <span class="badge {{ $typeClass }}">{{ $typeLabel }}</span>
                                </div>
                                <span class="mt-1 block text-sm text-base-content/60">{{ $description }}</span>
                            </div>
                        </label>
                    @endforeach
                </div>

                <div class="mt-4 text-sm text-base-content/60">
                    <x-icon name="o-information-circle" class="mr-1 inline size-4 align-text-bottom" />
                    Permissions update immediately after saving.
                </div>
            </x-card>
        </div>

        <div class="mt-3 flex justify-end gap-2">
            <a href="{{ route('admin.roles.index') }}" class="btn btn-outline">Cancel</a>
            <button type="submit" class="btn btn-success">
                <x-icon name="o-check" class="size-4" />
                Create role
            </button>
        </div>
    </form>
@endsection

@push('scripts')
    <script>
        function initRoleCreatePage() {
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

        document.addEventListener('codex:page-ready', initRoleCreatePage);
        initRoleCreatePage();
    </script>
@endpush
