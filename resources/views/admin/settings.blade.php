@extends('admin.settings.layout')

@section('settings-title', 'Admin settings')
@section('settings-subtitle', 'Find site-wide and feature settings without opening each section.')

@section('settings-content')
    @php
        $settingsUser = auth()->user();
        $canViewDiagnostics = $settingsUser->can('view-diagnostic-info');
        $canViewFeatureSettings = $settingsUser->canAny([
            'view-users',
            'view-members',
            'view-applications',
            'view-recruitment',
            'view-accounts',
            'view-lottery',
            'manage-lottery',
            'view-growth-circles',
            'view-loans',
            'view-market',
            'view-offshores',
            'view_payroll',
            'view-city-grants',
            'view-grants',
            'view-war-aid',
            'view-rebuilding',
            'view-raids',
            'view-mmr',
            'manage-war-room',
            'manage-custom-pages',
            'view-roles',
            'view-audits',
            'view-federation',
        ]);
    @endphp

    <div data-settings-directory class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_20rem] xl:items-start">
        <div class="space-y-6">
            <div class="nexus-panel p-4 sm:p-5">
                <label for="settings-search" class="block font-semibold text-base-content">Find a setting</label>
                <p class="mt-1 text-sm text-base-content/70">Search by name, feature, or task, such as "backup," "Discord," or "applications."</p>
                <div class="relative mt-4 max-w-3xl">
                    <x-icon name="o-magnifying-glass" class="pointer-events-none absolute left-3 top-1/2 size-5 -translate-y-1/2 text-base-content/60" aria-hidden="true" />
                    <input
                        type="search"
                        id="settings-search"
                        class="input w-full pl-10"
                        placeholder="Search settings"
                        autocomplete="off"
                        aria-controls="settings-directory-results"
                        data-settings-search
                    >
                </div>
                <p class="mt-2 text-sm text-base-content/70" aria-live="polite" data-settings-search-status>Showing all available settings.</p>
            </div>

            <div id="settings-directory-results" class="space-y-6">
                <section data-settings-directory-group>
                    <div class="mb-3">
                        <h2 class="text-lg font-semibold">Global settings</h2>
                        <p class="mt-1 text-sm text-base-content/70">Open a section to view or change its settings.</p>
                    </div>

                    <div class="nexus-panel divide-y divide-base-300 overflow-hidden">
                        @if ($canViewDiagnostics)
                            <x-admin.settings-link :href="route('admin.setup.index')" category="Operations" title="Setup & readiness" description="Review core installation readiness or rerun the guided alliance setup." keywords="onboarding first time setup readiness" />
                            <x-admin.settings-link :href="route('admin.settings.public-site') . '#homepage-messaging'" category="Public site" title="Homepage messaging" description="Headline, supporting copy, calls to action, and recruitment highlights." keywords="home landing page content copy" />
                            <x-admin.settings-link :href="route('admin.settings.public-site') . '#search-sharing'" category="Public site" title="Search and sharing" description="Search visibility, page details, and the image shown when pages are shared." keywords="seo metadata robots social open graph" />
                            <x-admin.settings-link :href="route('admin.settings.public-site') . '#favicon-settings'" category="Public site" title="Favicon" description="Browser tab and bookmark icon." keywords="icon logo browser" />

                            <x-admin.settings-link :href="route('admin.settings.discord.index') . '#discord-verification'" category="Discord" title="Verification requirement" description="Require an active Discord account after in-game verification." keywords="link account authentication" />
                            <x-admin.settings-link :href="route('admin.settings.discord.index') . '#discord-private-notifications'" category="Discord" title="Private Discord notifications" description="Allow users to choose which status updates they receive by direct message." keywords="dm direct message alerts" />
                            <x-admin.settings-link :href="route('admin.settings.discord.index') . '#discord-city-tiers'" category="Discord" title="City tier roles" description="Set which city range each Discord role covers." keywords="roles buckets" />
                            <x-admin.settings-link :href="route('admin.settings.discord.index') . '#discord-departures'" category="Discord" title="Alliance departure alerts" description="Notify a channel when a member leaves the alliance group." keywords="leave left channel alert" />
                        @endif

                        @can('manage-accounts')
                            <x-admin.settings-link :href="route('admin.settings.finance-policy') . '#auto-withdraw'" category="Finance policy" title="Automatic withdrawals" description="Turn scheduled withdrawals on or off for all accounts." keywords="bank account scheduler" />
                        @endcan

                        @can('manage-loans')
                            <x-admin.settings-link :href="route('admin.settings.finance-policy') . '#loan-payments'" category="Finance policy" title="Loan payments" description="Require or pause scheduled loan payments for all loans." keywords="war pause" />
                        @endcan

                        @can('manage-grants')
                            <x-admin.settings-link :href="route('admin.settings.finance-policy') . '#grant-approvals'" category="Finance policy" title="Grant approvals" description="Pause or resume grant approvals." keywords="city grants kill switch" />
                        @endcan

                        @can('edit-users')
                            <x-admin.settings-link :href="route('admin.settings.security-retention') . '#account-inactivity'" category="Security & retention" title="Automatic account disabling" description="Disable {{ config('app.name') }} accounts after a period without activity." keywords="users access security lifecycle" />
                        @endcan

                        @if ($canViewDiagnostics)
                            <x-admin.settings-link :href="route('admin.settings.data-sync') . '#data-synchronization'" category="Operations" title="Data synchronization" description="Review or start nation, alliance, war, and rolling updates." keywords="politics and war import queue jobs" />
                            <x-admin.settings-link :href="route('admin.settings.security-retention') . '#backup-settings'" category="Security & retention" title="Backups" description="Scheduled backup availability and recovery prerequisites." keywords="database archive recovery" />
                            <x-admin.settings-link :href="route('admin.settings.security-retention') . '#audit-retention'" category="Security & retention" title="Audit log retention" description="Choose how long audit records remain available." keywords="history cleanup records" />
                            <x-admin.settings-link :href="route('admin.settings.recovery') . '#pending-request-recovery'" category="Operations" title="Stuck request recovery" description="Close requests that are stuck in a pending state." keywords="diagnostics stuck force release" />
                            <x-admin.settings-link :href="route('admin.settings.system-health') . '#system-health-title'" category="Operations" title="System health" description="Check scheduled tasks, connected services, imports, and recent data updates." keywords="status diagnostics monitoring pulse" />
                        @endif

                        @can('view-federation')
                            <x-admin.settings-link :href="route('admin.federation.index')" category="Federation" title="Nexus Federation" description="Installation identity, peer links, coalition capabilities, shared war plans, and delivery health." keywords="peer nexus coalition sharing fingerprints encryption war plans" external />
                        @endcan
                    </div>
                </section>

                @if ($canViewFeatureSettings)
                    <section data-settings-directory-group>
                        <div class="mb-3">
                            <h2 class="text-lg font-semibold">Settings in feature areas</h2>
                            <p class="mt-1 text-sm text-base-content/70">Open a feature to manage its related settings.</p>
                        </div>

                        <div class="nexus-panel divide-y divide-base-300 overflow-hidden">
                            @can('view-members')
                                <x-admin.settings-link :href="route('admin.members') . '#member-inactivity-settings'" category="Members" title="Member inactivity automation" description="Thresholds, cooldowns, Discord channel, and automated member actions." keywords="nation roster activity vacation" external />
                            @endcan

                            @can('view-users')
                                <x-admin.settings-link :href="route('admin.users.index')" category="People & access" title="User administration" description="User access, account state, role assignment, password reset, and trusted-device recovery." keywords="accounts users security authentication" external />

                                @if ($settingsUser->can('edit-users') && $settingsUser->can('bypass-self-restrictions'))
                                    <x-admin.settings-link :href="route('admin.users.index') . '#mfa-requirements'" category="People & access" title="MFA requirements" description="Require an extra sign-in step for all users or administrators." keywords="two factor authentication security Fortify" external />
                                @endif
                            @endcan

                            @can('view-applications')
                                <x-admin.settings-link :href="route('admin.applications.index') . '#application-settings'" category="Applications" title="Application intake and Discord" description="Application availability, role mapping, announcement channel, and approval message." keywords="recruit applicants interview" external />
                            @endcan

                            @can('view-recruitment')
                                <x-admin.settings-link :href="route('admin.recruitment.index') . '#recruitment-settings'" category="Recruitment" title="Recruitment messaging" description="Recruitment availability, initial message, and follow-up content." keywords="outreach messages" external />
                            @endcan

                            @can('view-accounts')
                                <x-admin.settings-link :href="route('admin.accounts.dashboard')" category="Accounts" title="Account administration" description="Alliance accounts, pending withdrawals, balances, and transaction activity." keywords="bank balances transactions" external />

                                @can('view-dd')
                                    <x-admin.settings-link :href="route('admin.accounts.dashboard') . '#direct-deposit'" category="Accounts" title="Direct deposit" description="Direct deposit tax brackets by city." keywords="dd taxes banking" external />
                                @endcan
                            @endcan

                            @can('manage-accounts')
                                <x-admin.settings-link :href="route('admin.accounts.dashboard') . '#withdrawal-limits'" category="Accounts" title="Withdrawal limits" description="Member withdrawal limits and related account controls." keywords="bank limits transfers" external />
                            @endcan

                            @canany(['view-lottery', 'manage-lottery'])
                                <x-admin.settings-link :href="route('admin.lottery.index') . '#lottery-settings'" category="Weekly lottery" title="Lottery configuration" description="Sales availability, ticket price, jackpot share, and purchase limits." keywords="tickets jackpot" external />
                            @endcanany

                            @can('view-growth-circles')
                                <x-admin.settings-link :href="route('admin.growth-circles.index') . '#growth-circles-settings'" category="Growth Circles" title="Tax bracket mapping" description="Growth Circles and fallback tax bracket IDs." keywords="tax distribution" external />
                            @endcan

                            @can('view-loans')
                                <x-admin.settings-link :href="route('admin.loans') . '#loan-settings'" category="Loans" title="Loan defaults & applications" description="Default interest rate and member application availability." keywords="finance rate lending" external />
                            @endcan

                            @can('view-market')
                                <x-admin.settings-link :href="route('admin.market.index')" category="Alliance market" title="Resource pricing and purchase caps" description="Resource availability, price adjustments, and remaining purchase limits." keywords="market resources prices caps" external />
                            @endcan

                            @can('view-offshores')
                                <x-admin.settings-link :href="route('admin.offshores.index')" category="Offshores" title="Offshore accounts" description="Credentials, ordering, availability, and main-bank routing for offshore accounts." keywords="bank API keys sweep" external />
                            @endcan

                            @can('view-grants')
                                <x-admin.settings-link :href="route('admin.grants')" category="Grants" title="Grant programs" description="Program availability, payouts, requirements, and one-time policies." keywords="resources aid programs" external />
                            @endcan

                            @can('view-city-grants')
                                <x-admin.settings-link :href="route('admin.grants.city')" category="City grants" title="City grant tiers" description="City ranges, grant amounts, and eligibility requirements." keywords="cities programs tiers" external />
                            @endcan

                            @can('view_payroll')
                                <x-admin.settings-link :href="route('admin.payroll.index')" category="Payroll" title="Payroll grades and members" description="Daily payroll grades, assignments, and member compensation." keywords="salary payment grades" external />
                            @endcan

                            @can('view-raids')
                                <x-admin.settings-link :href="route('admin.raids.index')" category="Raids" title="Raid policy" description="No-raid alliances and the top-city policy cap." keywords="defense alliances targets" external />
                                <x-admin.settings-link :href="route('admin.beige-alerts.index') . '#beige-alert-settings'" category="Beige alerts" title="Alert channel and timing" description="Discord destination and alert lead time for beige exits." keywords="raids Discord turn" external />
                            @endcan

                            @can('view-war-aid')
                                <x-admin.settings-link :href="route('admin.war-aid')" category="War support" title="War aid availability" description="Review request status and control whether members can request war aid." keywords="defense requests toggle" external />
                            @endcan

                            @can('view-rebuilding')
                                <x-admin.settings-link :href="route('admin.rebuilding.index')" category="War support" title="Rebuilding policy" description="Turn rebuilding on or off, set tiers, and review eligibility rules." keywords="defense aid recovery" external />
                            @endcan

                            @can('view-mmr')
                                <x-admin.settings-link :href="route('admin.mmr.index') . '#mmr-assistant-settings'" category="MMR" title="Assistant resource settings" description="Choose eligible resources and extra costs for the MMR assistant." keywords="military requirements resources" external />
                            @endcan

                            @can('manage-war-room')
                                @if (config('milcom.v2_enabled', false))
                                    <x-admin.settings-link :href="route('admin.milcom.settings')" category="Defense" title="Milcom settings" description="War-planning settings." keywords="war room Discord defense" external />
                                @else
                                    <x-admin.settings-link :href="route('admin.war-room') . '#war-room-settings'" category="Defense" title="War room settings" description="Discord channel, forum, role, and automatic room creation." keywords="milcom war planning" external />
                                @endif
                            @endcan

                            @can('manage-custom-pages')
                                <x-admin.settings-link :href="route('admin.customization.index')" category="Public site" title="Custom pages" description="Draft, preview, publish, and restore public content pages." keywords="cms content editor" external />
                            @endcan

                            @can('view-roles')
                                <x-admin.settings-link :href="route('admin.roles.index')" category="People & access" title="Roles and permissions" description="Review role assignments and administrator access." keywords="rbac authorization access" external />
                            @endcan

                            @can('view-audits')
                                <x-admin.settings-link :href="route('admin.audits.rules.index')" category="Audits" title="Audit rules" description="Create and maintain compliance and member audit rules." keywords="violations checks" external />
                            @endcan
                        </div>
                    </section>
                @endif

                <div class="nexus-panel p-8 text-center" data-settings-no-results hidden>
                    <x-icon name="o-magnifying-glass" class="mx-auto size-8 text-base-content/40" aria-hidden="true" />
                    <h2 class="mt-3 font-semibold">No matching settings</h2>
                    <p class="mt-1 text-sm text-base-content/70">Try a broader term or clear the search.</p>
                </div>
            </div>
        </div>

        <aside class="nexus-panel p-4 xl:sticky xl:top-24" aria-label="Settings guidance">
            <h2 class="font-semibold">How this workspace is organized</h2>
            <ul class="mt-3 grid gap-3 text-sm text-base-content/70">
                <li class="flex gap-2">
                    <x-icon name="o-check-circle" class="mt-0.5 size-4 shrink-0 text-success" aria-hidden="true" />
                    <span><strong class="text-base-content">Site-wide settings</strong> have their own pages and save separately.</span>
                </li>
                <li class="flex gap-2">
                    <x-icon name="o-arrow-top-right-on-square" class="mt-0.5 size-4 shrink-0 text-primary" aria-hidden="true" />
                    <span><strong class="text-base-content">Feature settings</strong> stay with the feature they affect.</span>
                </li>
                <li class="flex gap-2">
                    <x-icon name="o-lock-closed" class="mt-0.5 size-4 shrink-0 text-base-content/60" aria-hidden="true" />
                    <span>You only see settings you can access.</span>
                </li>
            </ul>
        </aside>
    </div>

    @push('scripts')
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                const legacyDestinations = {
                    'settings-public': @json(route('admin.settings.public-site')),
                    'homepage-messaging': @json(route('admin.settings.public-site') . '#homepage-messaging'),
                    'search-sharing': @json(route('admin.settings.public-site') . '#search-sharing'),
                    'favicon-settings': @json(route('admin.settings.public-site') . '#favicon-settings'),
                    'settings-discord': @json(route('admin.settings.discord.index')),
                    'discord-verification': @json(route('admin.settings.discord.index') . '#discord-verification'),
                    'discord-private-notifications': @json(route('admin.settings.discord.index') . '#discord-private-notifications'),
                    'discord-city-tiers': @json(route('admin.settings.discord.index') . '#discord-city-tiers'),
                    'discord-departures': @json(route('admin.settings.discord.index') . '#discord-departures'),
                    'settings-finance': @json(route('admin.settings.finance-policy')),
                    'auto-withdraw': @json(route('admin.settings.finance-policy') . '#auto-withdraw'),
                    'loan-payments': @json(route('admin.settings.finance-policy') . '#loan-payments'),
                    'grant-approvals': @json(route('admin.settings.finance-policy') . '#grant-approvals'),
                    'settings-people': @json(route('admin.settings.security-retention')),
                    'account-inactivity': @json(route('admin.settings.security-retention') . '#account-inactivity'),
                    'settings-operations': @json(route('admin.settings.data-sync')),
                    'data-synchronization': @json(route('admin.settings.data-sync') . '#data-synchronization'),
                    'backup-settings': @json(route('admin.settings.security-retention') . '#backup-settings'),
                    'audit-retention': @json(route('admin.settings.security-retention') . '#audit-retention'),
                    'pending-request-recovery': @json(route('admin.settings.recovery') . '#pending-request-recovery'),
                    'system-health-title': @json(route('admin.settings.system-health') . '#system-health-title'),
                };
                let legacyHash = null;

                try {
                    legacyHash = window.location.hash ? decodeURIComponent(window.location.hash.slice(1)) : null;
                } catch {
                    legacyHash = null;
                }

                const legacyTarget = legacyHash ? legacyDestinations[legacyHash] : null;

                if (legacyTarget) {
                    window.location.replace(legacyTarget);

                    return;
                }

                const directory = document.querySelector('[data-settings-directory]');
                const search = directory?.querySelector('[data-settings-search]');

                if (!directory || !search) {
                    return;
                }

                const items = Array.from(directory.querySelectorAll('[data-settings-directory-item]'));
                const groups = Array.from(directory.querySelectorAll('[data-settings-directory-group]'));
                const noResults = directory.querySelector('[data-settings-no-results]');
                const searchStatus = directory.querySelector('[data-settings-search-status]');

                const filterDirectory = () => {
                    const query = search.value.trim().toLocaleLowerCase();
                    let visibleCount = 0;

                    items.forEach((item) => {
                        const haystack = `${item.textContent} ${item.dataset.settingsKeywords || ''}`.toLocaleLowerCase();
                        const isVisible = query === '' || haystack.includes(query);
                        item.hidden = !isVisible;
                        visibleCount += isVisible ? 1 : 0;
                    });

                    groups.forEach((group) => {
                        group.hidden = !Array.from(group.querySelectorAll('[data-settings-directory-item]')).some((item) => !item.hidden);
                    });

                    noResults.hidden = visibleCount !== 0;
                    searchStatus.textContent = query === ''
                        ? 'Showing all available settings.'
                        : `${visibleCount} ${visibleCount === 1 ? 'setting' : 'settings'} found.`;
                };

                search.addEventListener('input', filterDirectory);
                search.addEventListener('keydown', (event) => {
                    if (event.key === 'Escape' && search.value !== '') {
                        search.value = '';
                        filterDirectory();
                    }
                });
            });
        </script>
    @endpush
@endsection
