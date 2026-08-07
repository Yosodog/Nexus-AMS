@extends('layouts.admin')

@section('content')
    @php
        $settingsUser = auth()->user();
        $canViewDiagnostics = $settingsUser->can('view-diagnostic-info');
        $canViewFinanceSettings = $settingsUser->canAny(['manage-accounts', 'manage-loans', 'manage-grants']);
        $canViewPeopleSettings = $settingsUser->can('edit-users');
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
        ]);
        $syncRunning = $canViewDiagnostics && collect([$nationBatch, $rollingNationBatch, $allianceBatch, $warBatch])
            ->filter()
            ->contains(fn ($batch) => ! $batch->finished());
        $stalePendingCount = $canViewDiagnostics ? collect($pendingRecoveryItems)->sum('stalePending') : 0;
        $healthStatus = $canViewDiagnostics ? ($systemHealth['status'] ?? 'unknown') : 'unknown';
        $healthStatusLabel = match ($healthStatus) {
            'healthy' => 'Healthy',
            'warning' => 'Warning',
            'critical' => 'Critical',
            default => 'Unknown',
        };
        $healthStatusClass = match ($healthStatus) {
            'healthy' => 'badge-success',
            'warning' => 'badge-warning',
            'critical' => 'badge-error',
            default => 'badge-ghost',
        };
    @endphp

    <x-header title="Admin Settings" separator use-h1>
        <x-slot:subtitle>Find global controls quickly, review their current state, and jump to feature-specific configuration without hunting through the admin area.</x-slot:subtitle>
    </x-header>

    <div data-settings-shell data-default-panel="directory" class="space-y-6">
        <div class="nexus-panel overflow-hidden">
            <div class="flex flex-wrap items-start justify-between gap-3 border-b border-base-300 px-4 py-4 sm:px-5">
                <div>
                    <h2 class="font-semibold text-base-content">Settings workspace</h2>
                    <p class="mt-1 max-w-3xl text-sm text-base-content/70">Each setting saves independently. Use the directory to search this page and configuration owned by other features.</p>
                </div>
                <span class="badge badge-ghost">Permission-aware</span>
            </div>

            <div class="overflow-x-auto">
                <div role="tablist" aria-label="Settings categories" class="tabs tabs-border min-w-max px-2 sm:px-3">
                    <button
                        type="button"
                        id="settings-tab-directory"
                        role="tab"
                        class="tab tab-active h-12 whitespace-nowrap"
                        aria-selected="true"
                        aria-controls="settings-directory"
                        data-settings-tab="directory"
                    >Directory</button>

                    @if ($canViewDiagnostics)
                        <button type="button" id="settings-tab-public" role="tab" class="tab h-12 whitespace-nowrap" aria-selected="false" aria-controls="settings-public" tabindex="-1" data-settings-tab="public">Public site</button>
                        <button type="button" id="settings-tab-discord" role="tab" class="tab h-12 whitespace-nowrap" aria-selected="false" aria-controls="settings-discord" tabindex="-1" data-settings-tab="discord">Discord</button>
                    @endif

                    @if ($canViewFinanceSettings)
                        <button type="button" id="settings-tab-finance" role="tab" class="tab h-12 whitespace-nowrap" aria-selected="false" aria-controls="settings-finance" tabindex="-1" data-settings-tab="finance">Finance & workflows</button>
                    @endif

                    @if ($canViewPeopleSettings)
                        <button type="button" id="settings-tab-people" role="tab" class="tab h-12 whitespace-nowrap" aria-selected="false" aria-controls="settings-people" tabindex="-1" data-settings-tab="people">People & access</button>
                    @endif

                    @if ($canViewDiagnostics)
                        <button type="button" id="settings-tab-operations" role="tab" class="tab h-12 whitespace-nowrap" aria-selected="false" aria-controls="settings-operations" tabindex="-1" data-settings-tab="operations">Operations</button>
                    @endif
                </div>
            </div>
        </div>

        <section
            id="settings-directory"
            role="tabpanel"
            aria-labelledby="settings-tab-directory"
            tabindex="0"
            data-settings-panel="directory"
        >
            <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_20rem] xl:items-start">
                <div class="space-y-6">
                    <div class="nexus-panel p-4 sm:p-5">
                        <label for="settings-search" class="block font-semibold text-base-content">Find a setting</label>
                        <p class="mt-1 text-sm text-base-content/70">Search by name, feature, or task—such as “backup,” “Discord,” or “applications.”</p>
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
                        <p class="mt-2 text-xs text-base-content/60" aria-live="polite" data-settings-search-status>Showing all available settings.</p>
                    </div>

                    <div id="settings-directory-results" class="space-y-6">
                        <section data-settings-directory-group>
                            <div class="mb-3">
                                <h2 class="text-lg font-semibold">Global settings</h2>
                                <p class="mt-1 text-sm text-base-content/70">Controls edited directly on this page.</p>
                            </div>

                            <div class="nexus-panel divide-y divide-base-300 overflow-hidden">
                                @if ($canViewDiagnostics)
                                    <x-admin.settings-link href="#homepage-messaging" panel="public" category="Public site" title="Homepage Messaging" description="Headline, supporting copy, calls to action, and recruitment highlights." keywords="home landing page content copy" />
                                    <x-admin.settings-link href="#search-sharing" panel="public" category="Public site" title="Search & Sharing" description="Search indexing, page metadata, and social preview image." :status="$seoSettings['effective_indexing_enabled'] ? 'Indexable' : 'Noindex'" :status-class="$seoSettings['effective_indexing_enabled'] ? 'badge-success' : 'badge-warning'" keywords="seo metadata robots social open graph" />
                                    <x-admin.settings-link href="#favicon-settings" panel="public" category="Public site" title="Favicon" description="Browser tab and bookmark icon." status="Branding" keywords="icon logo browser" />

                                    <x-admin.settings-link href="#discord-verification" panel="discord" category="Discord" title="Verification requirement" description="Require an active Discord account after in-game verification." :status="$discordVerificationRequired ? 'Required' : 'Optional'" :status-class="$discordVerificationRequired ? 'badge-success' : 'badge-ghost'" keywords="link account authentication" />
                                    <x-admin.settings-link href="#discord-private-notifications" panel="discord" category="Discord" title="Private workflow notifications" description="Allow users to opt into minimal direct-message status updates." :status="$discordPrivateNotificationsEnabled ? 'Enabled' : 'Disabled'" :status-class="$discordPrivateNotificationsEnabled ? 'badge-success' : 'badge-ghost'" keywords="dm direct message alerts" />
                                    <x-admin.settings-link href="#discord-city-tiers" panel="discord" category="Discord" title="City tier roles" description="Choose the city-count range represented by each bot-managed role." :status="$discordCityTierBucketSize . ' cities'" keywords="roles buckets" />
                                    <x-admin.settings-link href="#discord-departures" panel="discord" category="Discord" title="Alliance departure alerts" description="Notify a channel when a member leaves the alliance group." :status="$discordDepartureEnabled ? 'Enabled' : 'Disabled'" :status-class="$discordDepartureEnabled ? 'badge-success' : 'badge-ghost'" keywords="leave left channel alert" />
                                @endif

                                @can('manage-accounts')
                                    <x-admin.settings-link href="#auto-withdraw" panel="finance" category="Finance & workflows" title="Auto Withdraw" description="Global scheduler availability for automatic withdrawals." :status="$autoWithdrawEnabled ? 'Enabled' : 'Disabled'" :status-class="$autoWithdrawEnabled ? 'badge-success' : 'badge-ghost'" keywords="bank account scheduler" />
                                @endcan

                                @can('manage-loans')
                                    <x-admin.settings-link href="#loan-payments" panel="finance" category="Finance & workflows" title="Loan Payments" description="Globally require or pause scheduled loan payments." :status="$loanPaymentsEnabled ? 'Enabled' : 'Paused'" :status-class="$loanPaymentsEnabled ? 'badge-success' : 'badge-warning'" keywords="war pause" />
                                @endcan

                                @can('manage-grants')
                                    <x-admin.settings-link href="#grant-approvals" panel="finance" category="Finance & workflows" title="Grant Approvals" description="Emergency availability switch for grant approvals." :status="$grantApprovalsEnabled ? 'Enabled' : 'Paused'" :status-class="$grantApprovalsEnabled ? 'badge-success' : 'badge-warning'" keywords="city grants kill switch" />
                                @endcan

                                @can('edit-users')
                                    <x-admin.settings-link href="#account-inactivity" panel="people" category="People & access" title="Account Inactivity Auto-Disable" description="Disable {{ config('app.name') }} user accounts after a period without activity." :status="$userInactivityAutoDisableEnabled ? 'Enabled' : 'Disabled'" :status-class="$userInactivityAutoDisableEnabled ? 'badge-success' : 'badge-ghost'" keywords="users access security lifecycle" />
                                @endcan

                                @if ($canViewDiagnostics)
                                    <x-admin.settings-link href="#data-synchronization" panel="operations" category="Operations" title="Data Synchronization" description="Manual nation, alliance, war, and rolling sync controls." :status="$syncRunning ? 'Running' : 'Manual controls'" :status-class="$syncRunning ? 'badge-info' : 'badge-ghost'" keywords="politics and war import queue jobs" />
                                    <x-admin.settings-link href="#backup-settings" panel="operations" category="Operations" title="Backups" description="Scheduled backup availability and recovery prerequisites." :status="$backupsEnabled ? 'Enabled' : 'Disabled'" :status-class="$backupsEnabled ? 'badge-success' : 'badge-ghost'" keywords="database archive recovery" />
                                    <x-admin.settings-link href="#audit-retention" panel="operations" category="Operations" title="Audit Log Retention" description="Number of days persisted audit records are retained." :status="$auditRetentionDays . ' days'" keywords="history cleanup records" />
                                    <x-admin.settings-link href="#pending-request-recovery" panel="operations" category="Operations" title="Pending Request Recovery" description="Release genuinely stuck pending workflow rows." :status="$stalePendingCount > 0 ? number_format($stalePendingCount) . ' stale' : 'No stale rows'" :status-class="$stalePendingCount > 0 ? 'badge-warning' : 'badge-ghost'" keywords="diagnostics stuck force release" />
                                    <x-admin.settings-link href="#system-health-title" panel="operations" category="Operations" title="System Health" description="Freshness checks for the scheduler, API, imports, and data pipelines." :status="$healthStatusLabel" :status-class="$healthStatusClass" keywords="status diagnostics monitoring pulse" />
                                @endif
                            </div>
                        </section>

                        @if ($canViewFeatureSettings)
                            <section data-settings-directory-group>
                                <div class="mb-3">
                                    <h2 class="text-lg font-semibold">Settings in feature areas</h2>
                                    <p class="mt-1 text-sm text-base-content/70">These controls stay with the workflows they affect; links open the exact feature context.</p>
                                </div>

                                <div class="nexus-panel divide-y divide-base-300 overflow-hidden">
                                    @can('view-members')
                                        <x-admin.settings-link :href="route('admin.members') . '#member-inactivity-settings'" category="Members" title="Member inactivity automation" description="Thresholds, cooldowns, Discord channel, and automated member actions." keywords="nation roster activity vacation" external />
                                    @endcan

                                    @can('view-users')
                                        <x-admin.settings-link :href="route('admin.users.index')" category="People & access" title="User administration" description="User access, account state, role assignment, password reset, and trusted-device recovery." keywords="accounts users security authentication" external />

                                        @if ($settingsUser->can('edit-users') && $settingsUser->can('bypass-self-restrictions'))
                                            <x-admin.settings-link :href="route('admin.users.index') . '#mfa-requirements'" category="People & access" title="MFA requirements" description="Require multifactor enrollment for all users or administrators." keywords="two factor authentication security Fortify" external />
                                        @endif
                                    @endcan

                                    @can('view-applications')
                                        <x-admin.settings-link :href="route('admin.applications.index') . '#application-settings'" category="Applications" title="Application intake & Discord handoff" description="Application availability, role mapping, announcement channel, and approval message." keywords="recruit applicants interview" external />
                                    @endcan

                                    @can('view-recruitment')
                                        <x-admin.settings-link :href="route('admin.recruitment.index') . '#recruitment-settings'" category="Recruitment" title="Recruitment messaging" description="Recruitment availability, initial message, and follow-up content." keywords="outreach messages" external />
                                    @endcan

                                    @can('view-accounts')
                                        <x-admin.settings-link :href="route('admin.accounts.dashboard')" category="Accounts" title="Account administration" description="Alliance accounts, pending withdrawals, balances, and transaction activity." keywords="bank balances transactions" external />

                                        @can('view-dd')
                                            <x-admin.settings-link :href="route('admin.accounts.dashboard') . '#direct-deposit'" category="Accounts" title="Direct deposit" description="Tax bracket IDs and city-based direct-deposit bracket rules." keywords="dd taxes banking" external />
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
                                        <x-admin.settings-link :href="route('admin.market.index')" category="Alliance market" title="Resource pricing & purchase caps" description="Per-resource availability, price adjustments, and remaining purchase limits." keywords="market resources prices caps" external />
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
                                        <x-admin.settings-link :href="route('admin.payroll.index')" category="Payroll" title="Payroll grades & members" description="Daily payroll grades, assignments, and member compensation." keywords="salary payment grades" external />
                                    @endcan

                                    @can('view-raids')
                                        <x-admin.settings-link :href="route('admin.raids.index')" category="Raids" title="Raid policy" description="No-raid alliances and the top-city policy cap." keywords="defense alliances targets" external />
                                        <x-admin.settings-link :href="route('admin.beige-alerts.index') . '#beige-alert-settings'" category="Beige alerts" title="Alert channel & timing" description="Discord destination and alert lead time for beige exits." keywords="raids Discord turn" external />
                                    @endcan

                                    @can('view-war-aid')
                                        <x-admin.settings-link :href="route('admin.war-aid')" category="War support" title="War aid availability" description="Review workflow status and control whether members can request war aid." keywords="defense requests toggle" external />
                                    @endcan

                                    @can('view-rebuilding')
                                        <x-admin.settings-link :href="route('admin.rebuilding.index')" category="War support" title="Rebuilding policy" description="Workflow availability, tiers, cycle state, and eligibility exclusions." keywords="defense aid recovery" external />
                                    @endcan

                                    @can('view-mmr')
                                        <x-admin.settings-link :href="route('admin.mmr.index') . '#mmr-assistant-settings'" category="MMR" title="Assistant resource settings" description="Resource eligibility and surcharge percentages for the MMR assistant." keywords="military requirements resources" external />
                                    @endcan

                                    @can('manage-war-room')
                                        @if (config('milcom.v2_enabled', false))
                                            <x-admin.settings-link :href="route('admin.milcom.settings')" category="Defense" title="Milcom settings" description="War planning and Milcom workflow configuration." keywords="war room Discord defense" external />
                                        @else
                                            <x-admin.settings-link :href="route('admin.war-room') . '#war-room-settings'" category="Defense" title="War room settings" description="Discord channel, forum, role, and automatic room creation." keywords="milcom war planning" external />
                                        @endif
                                    @endcan

                                    @can('manage-custom-pages')
                                        <x-admin.settings-link :href="route('admin.customization.index')" category="Public site" title="Custom pages" description="Draft, preview, publish, and restore public content pages." keywords="cms content editor" external />
                                    @endcan

                                    @can('view-roles')
                                        <x-admin.settings-link :href="route('admin.roles.index')" category="People & access" title="Roles & permissions" description="Review role assignments and administrative capabilities." keywords="rbac authorization access" external />
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
                    <h2 class="font-semibold">How this page is organized</h2>
                    <ul class="mt-3 grid gap-3 text-sm text-base-content/70">
                        <li class="flex gap-2">
                            <x-icon name="o-check-circle" class="mt-0.5 size-4 shrink-0 text-success" aria-hidden="true" />
                            <span><strong class="text-base-content">Global settings</strong> are edited here and save independently.</span>
                        </li>
                        <li class="flex gap-2">
                            <x-icon name="o-arrow-top-right-on-square" class="mt-0.5 size-4 shrink-0 text-primary" aria-hidden="true" />
                            <span><strong class="text-base-content">Feature settings</strong> open in the screen that owns the workflow.</span>
                        </li>
                        <li class="flex gap-2">
                            <x-icon name="o-lock-closed" class="mt-0.5 size-4 shrink-0 text-base-content/60" aria-hidden="true" />
                            <span>You only see controls and destinations your permissions allow.</span>
                        </li>
                    </ul>
                </aside>
            </div>
        </section>

        @if ($canViewDiagnostics)
            <section id="settings-public" role="tabpanel" aria-labelledby="settings-tab-public" tabindex="0" data-settings-panel="public" hidden>
                @include('admin.settings.partials.public-site')
            </section>

            <section id="settings-discord" role="tabpanel" aria-labelledby="settings-tab-discord" tabindex="0" data-settings-panel="discord" hidden>
                @include('admin.settings.partials.discord')
            </section>
        @endif

        @if ($canViewFinanceSettings)
            <section id="settings-finance" role="tabpanel" aria-labelledby="settings-tab-finance" tabindex="0" data-settings-panel="finance" hidden>
                @include('admin.settings.partials.finance')
            </section>
        @endif

        @if ($canViewPeopleSettings)
            <section id="settings-people" role="tabpanel" aria-labelledby="settings-tab-people" tabindex="0" data-settings-panel="people" hidden>
                @include('admin.settings.partials.people')
            </section>
        @endif

        @if ($canViewDiagnostics)
            <section id="settings-operations" role="tabpanel" aria-labelledby="settings-tab-operations" tabindex="0" data-settings-panel="operations" hidden>
                @include('admin.settings.partials.operations')
            </section>
        @endif
    </div>

    @push('scripts')
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                const shell = document.querySelector('[data-settings-shell]');

                if (!shell) {
                    return;
                }

                const tabs = Array.from(shell.querySelectorAll('[data-settings-tab]'));
                const panels = Array.from(shell.querySelectorAll('[data-settings-panel]'));
                const panelNames = new Set(panels.map((panel) => panel.dataset.settingsPanel));
                const storageKey = 'nexus.admin.settings.active-panel';
                const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

                const activatePanel = (requestedPanel, options = {}) => {
                    const panelName = panelNames.has(requestedPanel) ? requestedPanel : shell.dataset.defaultPanel;

                    tabs.forEach((tab) => {
                        const isActive = tab.dataset.settingsTab === panelName;
                        tab.classList.toggle('tab-active', isActive);
                        tab.setAttribute('aria-selected', String(isActive));
                        tab.tabIndex = isActive ? 0 : -1;
                    });

                    panels.forEach((panel) => {
                        panel.hidden = panel.dataset.settingsPanel !== panelName;
                    });

                    sessionStorage.setItem(storageKey, panelName);

                    if (options.focusTab) {
                        tabs.find((tab) => tab.dataset.settingsTab === panelName)?.focus();
                    }

                    return panelName;
                };

                const revealTarget = (target) => {
                    if (!target) {
                        return;
                    }

                    const disclosure = target.matches('[data-settings-disclosure]')
                        ? target
                        : target.closest('[data-settings-disclosure]');

                    if (disclosure) {
                        disclosure.open = true;
                    }

                    window.requestAnimationFrame(() => {
                        target.scrollIntoView({
                            behavior: prefersReducedMotion ? 'auto' : 'smooth',
                            block: 'start',
                        });
                    });
                };

                const hashTarget = window.location.hash ? document.getElementById(decodeURIComponent(window.location.hash.slice(1))) : null;
                const hashPanel = hashTarget?.closest('[data-settings-panel]')?.dataset.settingsPanel;
                const storedPanel = sessionStorage.getItem(storageKey);
                const initialPanel = hashPanel || (panelNames.has(storedPanel) ? storedPanel : shell.dataset.defaultPanel);

                activatePanel(initialPanel);
                revealTarget(hashTarget);

                tabs.forEach((tab, index) => {
                    tab.addEventListener('click', () => {
                        const panelName = activatePanel(tab.dataset.settingsTab);
                        window.history.replaceState(null, '', `#settings-${panelName}`);
                    });

                    tab.addEventListener('keydown', (event) => {
                        let nextIndex = null;

                        if (event.key === 'ArrowRight') {
                            nextIndex = (index + 1) % tabs.length;
                        } else if (event.key === 'ArrowLeft') {
                            nextIndex = (index - 1 + tabs.length) % tabs.length;
                        } else if (event.key === 'Home') {
                            nextIndex = 0;
                        } else if (event.key === 'End') {
                            nextIndex = tabs.length - 1;
                        }

                        if (nextIndex === null) {
                            return;
                        }

                        event.preventDefault();
                        activatePanel(tabs[nextIndex].dataset.settingsTab, { focusTab: true });
                    });
                });

                shell.querySelectorAll('[data-settings-open]').forEach((link) => {
                    link.addEventListener('click', (event) => {
                        const target = link.hash ? document.getElementById(decodeURIComponent(link.hash.slice(1))) : null;

                        if (!target) {
                            return;
                        }

                        event.preventDefault();
                        activatePanel(link.dataset.settingsOpen);
                        window.history.replaceState(null, '', link.hash);
                        revealTarget(target);
                    });
                });

                const search = shell.querySelector('[data-settings-search]');
                const items = Array.from(shell.querySelectorAll('[data-settings-directory-item]'));
                const groups = Array.from(shell.querySelectorAll('[data-settings-directory-group]'));
                const noResults = shell.querySelector('[data-settings-no-results]');
                const searchStatus = shell.querySelector('[data-settings-search-status]');

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

                search?.addEventListener('input', filterDirectory);
                search?.addEventListener('keydown', (event) => {
                    if (event.key === 'Escape' && search.value !== '') {
                        search.value = '';
                        filterDirectory();
                    }
                });
            });
        </script>
    @endpush
@endsection
