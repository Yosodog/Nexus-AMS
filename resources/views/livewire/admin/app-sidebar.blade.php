<div class="pt-2">
    <x-menu activate-by-route>

        {{-- Dashboard --}}
        <x-menu-item no-wire-navigate title="Dashboard" icon="o-squares-2x2" route="admin.dashboard" />

        {{-- User Management --}}
        <x-menu-separator title="Alliance" />

        <x-menu-item no-wire-navigate
            title="Members"
            icon="o-users"
            route="admin.members"
            :hidden="! auth()->user()?->can('view-members')"
        />

        <x-menu-item no-wire-navigate
            title="Cities"
            icon="o-building-office-2"
            :link="route('admin.cities.index')"
            :active="request()->routeIs('admin.cities.*')"
            :hidden="! auth()->user()?->can('view-members')"
        />

        <x-menu-sub title="User Management" icon="o-identification">
            <x-menu-item no-wire-navigate
                title="Manage Users"
                icon="o-user-group"
                :link="route('admin.users.index')"
                :active="request()->routeIs('admin.users.*')"
                :hidden="! auth()->user()?->can('view-users')"
            />
            <x-menu-item no-wire-navigate
                title="Manage Roles"
                icon="o-shield-check"
                :link="route('admin.roles.index')"
                :active="request()->routeIs('admin.roles.*')"
                :hidden="! auth()->user()?->can('view-roles')"
            />
        </x-menu-sub>

        {{-- Economics --}}
        <x-menu-separator title="Economics" />

        <x-menu-item no-wire-navigate
            title="Accounts"
            icon="o-building-library"
            :link="route('admin.accounts.dashboard')"
            :active="request()->routeIs('admin.accounts.*')"
            :badge="$financePending > 0 ? (string) $financePending : null"
            badge-classes="badge-primary badge-sm"
            :hidden="! auth()->user()?->can('view-accounts')"
        />

        <x-menu-sub
            title="Grants"
            icon="o-home-modern"
            :open="in_array(request()->route()?->getName(), ['admin.grants.city', 'admin.grants'], true)"
        >
            <x-menu-item no-wire-navigate
                title="City Grants"
                icon="o-home"
                :link="route('admin.grants.city')"
                :active="request()->route()?->getName() === 'admin.grants.city'"
                :badge="($pendingCounts['city_grants'] ?? 0) > 0 ? (string) ($pendingCounts['city_grants'] ?? 0) : null"
                badge-classes="badge-primary badge-sm"
                :hidden="! auth()->user()?->can('view-city-grants')"
            />
            <x-menu-item no-wire-navigate
                title="Grants"
                icon="o-gift"
                :link="route('admin.grants')"
                :active="request()->route()?->getName() === 'admin.grants'"
                :badge="($pendingCounts['grants'] ?? 0) > 0 ? (string) ($pendingCounts['grants'] ?? 0) : null"
                badge-classes="badge-primary badge-sm"
                :hidden="! auth()->user()?->can('view-grants')"
            />
        </x-menu-sub>

        <x-menu-item no-wire-navigate
            title="Loans"
            icon="o-banknotes"
            :link="route('admin.loans')"
            :active="request()->routeIs('admin.loans')"
            :badge="($pendingCounts['loans'] ?? 0) > 0 ? (string) ($pendingCounts['loans'] ?? 0) : null"
            badge-classes="badge-primary badge-sm"
            :hidden="! auth()->user()?->can('view-loans')"
        />

        <x-menu-item no-wire-navigate
            title="Taxes"
            icon="o-receipt-percent"
            route="admin.taxes"
            :hidden="! auth()->user()?->can('view-taxes')"
        />

        <x-menu-sub
            title="Finance"
            icon="o-currency-dollar"
            :open="request()->routeIs('admin.offshores.*', 'admin.finance.*', 'admin.payroll.*')"
        >
            <x-menu-item no-wire-navigate
                title="Offshores"
                icon="o-globe-alt"
                :link="route('admin.offshores.index')"
                :active="request()->routeIs('admin.offshores.*')"
                :hidden="! auth()->user()?->can('view-offshores')"
            />
            <x-menu-item no-wire-navigate
                title="Finance Ledger"
                icon="o-book-open"
                :link="route('admin.finance.index')"
                :active="request()->routeIs('admin.finance.*')"
                :hidden="! auth()->user()?->can('view-financial-reports')"
            />
            <x-menu-item no-wire-navigate
                title="Payroll"
                icon="o-currency-dollar"
                :link="route('admin.payroll.index')"
                :active="request()->routeIs('admin.payroll.*')"
                :hidden="! auth()->user()?->can('view_payroll')"
            />
            <x-menu-item no-wire-navigate
                title="Alliance Market"
                icon="o-shopping-bag"
                :link="route('admin.market.index')"
                :active="request()->routeIs('admin.market.*')"
                :hidden="! auth()->user()?->can('view-market')"
            />
        </x-menu-sub>

        {{-- Defense --}}
        <x-menu-separator title="Defense" />

        <x-menu-sub
            title="Wars"
            icon="o-bolt"
            :open="request()->routeIs('admin.war-room', 'admin.wars', 'admin.war-aid', 'admin.rebuilding.*', 'admin.raids.*', 'admin.beige-alerts.*', 'admin.spy-campaigns.*')"
        >
            <x-menu-item no-wire-navigate
                title="War Room"
                icon="o-command-line"
                :link="route('admin.war-room')"
                :active="request()->routeIs('admin.war-room')"
                :hidden="! auth()->user()?->can('view-wars')"
            />
            <x-menu-item no-wire-navigate
                title="Wars"
                icon="o-chart-bar"
                :link="route('admin.wars')"
                :active="request()->routeIs('admin.wars')"
                :hidden="! auth()->user()?->can('view-wars')"
            />
            <x-menu-item no-wire-navigate
                title="War Aid"
                icon="o-heart"
                :link="route('admin.war-aid')"
                :active="request()->routeIs('admin.war-aid')"
                :badge="($pendingCounts['war_aid'] ?? 0) > 0 ? (string) ($pendingCounts['war_aid'] ?? 0) : null"
                badge-classes="badge-warning badge-sm"
                :hidden="! auth()->user()?->can('view-war-aid')"
            />
            <x-menu-item no-wire-navigate
                title="Rebuilding"
                icon="o-wrench-screwdriver"
                :link="route('admin.rebuilding.index')"
                :active="request()->routeIs('admin.rebuilding.*')"
                :badge="($pendingCounts['rebuilding'] ?? 0) > 0 ? (string) ($pendingCounts['rebuilding'] ?? 0) : null"
                badge-classes="badge-warning badge-sm"
                :hidden="! auth()->user()?->can('view-rebuilding')"
            />
            <x-menu-item no-wire-navigate
                title="Raids"
                icon="o-arrow-trending-up"
                :link="route('admin.raids.index')"
                :active="request()->routeIs('admin.raids.*')"
                :hidden="! auth()->user()?->can('view-raids')"
            />
            <x-menu-item no-wire-navigate
                title="Beige Alerts"
                icon="o-bell-alert"
                :link="route('admin.beige-alerts.index')"
                :active="request()->routeIs('admin.beige-alerts.*')"
                :hidden="! auth()->user()?->can('view-raids')"
            />
            <x-menu-item no-wire-navigate
                title="Spy Campaigns"
                icon="o-eye"
                :link="route('admin.spy-campaigns.index')"
                :active="request()->routeIs('admin.spy-campaigns.*')"
                :hidden="! auth()->user()?->can('view-spies')"
            />
        </x-menu-sub>

        <x-menu-item no-wire-navigate
            title="MMR"
            icon="o-shield-exclamation"
            :link="route('admin.mmr.index')"
            :active="request()->routeIs('admin.mmr.*')"
            :hidden="! auth()->user()?->can('view-raids')"
        />

        {{-- Internal Affairs --}}
        <x-menu-separator title="Internal Affairs" />

        <x-menu-sub
            title="Intake"
            icon="o-user-plus"
            :open="request()->routeIs('admin.applications.*', 'admin.recruitment.*')"
        >
            <x-menu-item no-wire-navigate
                title="Applications"
                icon="o-document-text"
                :link="route('admin.applications.index')"
                :active="request()->routeIs('admin.applications.*')"
                :hidden="! auth()->user()?->can('view-applications')"
            />
            <x-menu-item no-wire-navigate
                title="Recruitment"
                icon="o-envelope"
                :link="route('admin.recruitment.index')"
                :active="request()->routeIs('admin.recruitment.*')"
                :hidden="! auth()->user()?->can('view-recruitment')"
            />
        </x-menu-sub>

        <x-menu-item no-wire-navigate
            title="Audits"
            icon="o-shield-check"
            :link="route('admin.audits.index')"
            :active="request()->routeIs('admin.audits.*')"
            :hidden="! auth()->user()?->can('view-diagnostic-info')"
        />

        {{-- System --}}
        <x-menu-separator title="System" />

        <x-menu-sub
            title="Configuration"
            icon="o-cog-6-tooth"
            :open="request()->routeIs('admin.settings', 'admin.nel.docs', 'admin.customization.*')"
        >
            <x-menu-item no-wire-navigate
                title="Settings"
                icon="o-adjustments-horizontal"
                route="admin.settings"
                :hidden="! auth()->user()?->can('view-diagnostic-info')"
            />
            <x-menu-item no-wire-navigate
                title="NEL Docs"
                icon="o-code-bracket"
                route="admin.nel.docs"
                :hidden="! auth()->user()?->can('view-diagnostic-info')"
            />
            <x-menu-item no-wire-navigate
                title="Customize Pages"
                icon="o-paint-brush"
                :link="route('admin.customization.index')"
                :active="request()->routeIs('admin.customization.*')"
                :hidden="! auth()->user()?->can('manage-custom-pages')"
            />
        </x-menu-sub>

        <x-menu-sub
            title="Monitoring"
            icon="o-chart-bar-square"
            :open="request()->is('telescope', 'pulse', 'log-viewer') || request()->routeIs('admin.audit-logs.*')"
        >
            <x-menu-item no-wire-navigate
                title="Audit Logs"
                icon="o-clipboard-document-list"
                :link="route('admin.audit-logs.index')"
                :active="request()->routeIs('admin.audit-logs.*')"
                :hidden="! auth()->user()?->can('view-diagnostic-info')"
            />
            <x-menu-item no-wire-navigate
                title="Telescope"
                icon="o-bug-ant"
                :link="url('/telescope')"
                :active="request()->is('telescope')"
                :hidden="! auth()->user()?->can('view-diagnostic-info')"
            />
            <x-menu-item no-wire-navigate
                title="Pulse"
                icon="o-signal"
                :link="url('/pulse')"
                :active="request()->is('pulse')"
                :hidden="! auth()->user()?->can('view-diagnostic-info')"
            />
            <x-menu-item no-wire-navigate
                title="Log Viewer"
                icon="o-document-magnifying-glass"
                :link="url('/log-viewer')"
                :active="request()->is('log-viewer')"
                :hidden="! auth()->user()?->can('view-diagnostic-info')"
            />
        </x-menu-sub>

    </x-menu>
</div>
