<header class="admin-topbar">
    <div class="admin-topbar__inner">
        <label for="admin-sidebar" class="btn btn-ghost btn-circle btn-sm lg:hidden" aria-label="Open administrative navigation">
            <x-icon name="o-bars-3" class="size-5" />
        </label>

        <a href="{{ route('admin.dashboard') }}" class="admin-topbar__brand">
            <span class="admin-topbar__mark" aria-hidden="true">N</span>
            <span class="min-w-0">
                <span class="admin-topbar__name">{{ config('app.name') }}</span>
                <span class="admin-topbar__descriptor">Administration</span>
            </span>
        </a>

        <div class="admin-topbar__context">
            <span class="nexus-kicker">Staff workspace</span>
        </div>

        <div class="admin-topbar__actions">
            <a href="{{ route('user.dashboard') }}" class="btn btn-ghost btn-sm hidden sm:inline-flex">
                <x-icon name="o-arrow-left" class="size-4" />
                Member app
            </a>

            <x-theme-picker />

            @if($user)
                <details class="account-control">
                    <summary class="account-control__trigger" aria-label="Open staff account menu">
                        @if($nation?->flag)
                            <img src="{{ $nation->flag }}" alt="" class="account-control__avatar">
                        @else
                            <span class="account-control__fallback" aria-hidden="true">
                                {{ str($user->name)->substr(0, 1)->upper() }}
                            </span>
                        @endif
                    </summary>

                    <div class="account-menu">
                        <div class="account-menu__identity">
                            <p>{{ $nation?->leader_name ?? $user->name }}</p>
                            <p>{{ $nation?->nation_name ?? $user->email }}</p>
                            <span class="nexus-status nexus-status--neutral mt-2">Administrative access</span>
                        </div>

                        <nav class="account-menu__links" aria-label="Staff account navigation">
                            <a href="{{ route('user.dashboard') }}">
                                <x-icon name="o-arrow-left" class="size-4" />
                                Member application
                            </a>
                            <a href="{{ route('admin.dashboard') }}">
                                <x-icon name="o-squares-2x2" class="size-4" />
                                Admin overview
                            </a>
                            @can('view-members')
                                @if($user->nation_id)
                                    <a href="{{ route('admin.members.show', $user->nation_id) }}">
                                        <x-icon name="o-identification" class="size-4" />
                                        My member record
                                    </a>
                                @endif
                            @endcan
                            <button type="button" wire:click="logout" class="account-menu__logout">
                                <x-icon name="o-arrow-right-on-rectangle" class="size-4" />
                                Sign out
                            </button>
                        </nav>
                    </div>
                </details>
            @endif
        </div>
    </div>
</header>
