@extends('layouts.main')

@section('content')
    @php
        $nextCity = $nextCityNumber;
        $approvedCount = $grantRequests->where('status', 'approved')->count();
        $pendingCount = $grantRequests->where('status', 'pending')->count();
        $deniedCount = $grantRequests->where('status', 'denied')->count();
        $approvedTotal = $grantRequests->where('status', 'approved')->sum('grant_amount');

        $nextGrant = $grants->firstWhere('city_number', $nextCity);
        $nextGrantAmount = $nextGrant ? ($grantAmounts[$nextGrant->id] ?? null) : null;
        $nextEligibilityReport = $nextEligibilityReport ?? ['passes' => true, 'failures' => [], 'summary' => []];
        $nextRequirementSummary = collect($nextGrant?->requirement_summary ?? ($nextEligibilityReport['summary'] ?? []))
            ->values()
            ->all();
        $nextEligibilityFailures = collect($nextEligibilityReport['failures'] ?? [])->values()->all();
        $nextRequirementsPass = ($nextEligibilityReport['passes'] ?? false) === true;
        $hasEligibilityFailures = $nextEligibilityFailures !== [];
        $requirementsNotMet = ! $nextRequirementsPass || $hasEligibilityFailures;

        $pendingRequest = $grantRequests->firstWhere('status', 'pending');
        $hasPendingRequest = $pendingRequest !== null;

        $grantsCompletedCount = $grants->where('city_number', '<', $nextCity)->count();
        $grantsTotalCount = $grants->count();
        $progressPercent = $grantsTotalCount > 0
            ? min(100, (int) round(($grantsCompletedCount / $grantsTotalCount) * 100))
            : 0;
    @endphp

    <div class="mx-auto w-full min-w-0 space-y-8 lg:space-y-10">
        <section class="rounded-lg border border-base-300 border-l-4 border-l-primary bg-base-100 p-6 shadow-sm lg:p-8">
            <div class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
                <div class="space-y-3">
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-primary/70">Growth funding hub</p>
                    <h1 class="text-3xl font-extrabold text-base-content lg:text-4xl">City Grants</h1>
                    <p class="max-w-2xl text-sm text-base-content/75 lg:text-base">
                        Request city expansion funding, track review status, and see your grant roadmap in city order.
                    </p>
                </div>

                <div class="grid w-full grid-cols-2 gap-3 sm:w-auto sm:grid-cols-4">
                    <div class="rounded-xl border border-primary/30 bg-primary/10 px-4 py-3 text-primary">
                        <p class="text-[11px] uppercase tracking-wide">Next city</p>
                        <p class="text-xl font-extrabold">#{{ $nextCity }}</p>
                    </div>
                    <div class="rounded-xl border border-base-300 bg-base-100 px-4 py-3">
                        <p class="text-[11px] uppercase tracking-wide text-base-content/70">Pending</p>
                        <p class="text-lg font-bold text-base-content">{{ $pendingCount }}</p>
                    </div>
                    <div class="rounded-xl border border-base-300 bg-base-100 px-4 py-3">
                        <p class="text-[11px] uppercase tracking-wide text-base-content/70">Approved</p>
                        <p class="text-lg font-bold text-base-content">{{ $approvedCount }}</p>
                    </div>
                    <div class="rounded-xl border border-base-300 bg-base-100 px-4 py-3">
                        <p class="text-[11px] uppercase tracking-wide text-base-content/70">Total funded</p>
                        <p class="text-lg font-bold text-base-content">${{ number_format($approvedTotal) }}</p>
                    </div>
                </div>
            </div>

            <div class="relative mt-6 grid gap-4 lg:grid-cols-3">
                <div class="rounded-xl border border-base-300 bg-base-100/90 p-4 lg:col-span-2">
                    <div class="mb-2 flex items-center justify-between">
                        <h2 class="text-sm font-semibold uppercase tracking-wide text-base-content/75">Grant progression</h2>
                        <span class="text-xs font-semibold text-primary">{{ $progressPercent }}% complete</span>
                    </div>
                    <progress class="progress progress-primary h-2 w-full" value="{{ $progressPercent }}" max="100"></progress>
                    <p class="mt-2 text-xs text-base-content/70">
                        {{ number_format($grantsCompletedCount) }} of {{ number_format($grantsTotalCount) }} configured city grants are behind your current city level.
                    </p>
                </div>

                <div class="rounded-xl border border-base-300 bg-base-100/90 p-4 text-sm">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-base-content/75">City market data</h2>
                    <p class="mt-1 text-base-content/70">Top 20% city-cost benchmark</p>
                    @if ($cityAverage !== null)
                        <p class="mt-2 text-xl font-extrabold text-base-content">{{ number_format($cityAverage, 2) }}</p>
                        @if ($cityAverageUpdatedAt)
                            <p class="text-xs nexus-text-muted">Updated {{ $cityAverageUpdatedAt->diffForHumans() }}</p>
                        @endif
                    @else
                        <p class="mt-2 text-sm font-medium text-warning">Temporarily unavailable</p>
                    @endif
                </div>
            </div>
        </section>

        <section class="grid gap-6 xl:grid-cols-5">
            <div class="xl:col-span-3">
                <div class="card border border-primary/20 bg-base-100 shadow-md">
                    <div class="card-body space-y-5">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h2 class="text-xl font-bold text-base-content">Request City #{{ $nextCity }} grant</h2>
                                <p class="text-sm text-base-content/70">
                                    Select a destination account and submit for economics review.
                                </p>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                @if ($nextGrant)
                                    <span @class([
                                        'badge badge-outline text-xs',
                                        'badge-success' => ! $requirementsNotMet,
                                        'badge-warning' => $requirementsNotMet,
                                    ])>
                                        {{ $requirementsNotMet ? 'Requirements not met' : 'Eligible' }}
                                    </span>
                                @endif
                                @if ($hasPendingRequest)
                                    <span class="badge badge-warning badge-outline text-xs">Already pending</span>
                                @endif
                            </div>
                        </div>

                        @if ($nextGrant)
                            <div class="grid gap-3 rounded-xl border border-base-300 bg-base-200/60 p-4 sm:grid-cols-3">
                                <div>
                                    <p class="text-xs uppercase tracking-wide nexus-text-muted">Grant step</p>
                                    <p class="text-base font-bold text-base-content">City #{{ $nextGrant->city_number }}</p>
                                </div>
                                <div>
                                    <p class="text-xs uppercase tracking-wide nexus-text-muted">Coverage</p>
                                    <p class="text-base font-bold text-base-content">{{ number_format($nextGrant->grant_amount) }}%</p>
                                </div>
                                <div>
                                    <p class="text-xs uppercase tracking-wide nexus-text-muted">Estimated amount</p>
                                    <p @class([
                                        'text-base font-bold',
                                        'text-base-content' => $nextGrantAmount !== null,
                                        'text-warning' => $nextGrantAmount === null,
                                    ])>
                                        {{ $nextGrantAmount !== null ? '$' . number_format($nextGrantAmount) : 'Unavailable' }}
                                    </p>
                                </div>
                            </div>

                            <section class="space-y-3 rounded-xl border border-base-300 bg-base-100 p-4" aria-labelledby="next-city-grant-requirements">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <h3 id="next-city-grant-requirements" class="text-sm font-semibold uppercase tracking-wide text-base-content/75">Eligibility requirements</h3>
                                    <span @class([
                                        'badge badge-sm badge-outline',
                                        'badge-success' => ! $requirementsNotMet,
                                        'badge-warning' => $requirementsNotMet,
                                    ])>
                                        {{ $requirementsNotMet ? 'Not met' : 'Eligible' }}
                                    </span>
                                </div>

                                @if ($nextRequirementSummary !== [])
                                    <div class="space-y-2">
                                        @foreach ($nextRequirementSummary as $summary)
                                            <div class="flex items-start gap-2 rounded-lg bg-base-200/70 px-3 py-2 text-sm text-base-content/80">
                                                <span class="mt-0.5 text-primary" aria-hidden="true">•</span>
                                                <span>{{ $summary }}</span>
                                            </div>
                                        @endforeach
                                    </div>
                                @else
                                    <p class="rounded-lg border border-dashed border-base-300 bg-base-200/50 px-3 py-2 text-sm text-base-content/70">
                                        This city grant has no custom requirements beyond the standard application checks.
                                    </p>
                                @endif

                                @if ($requirementsNotMet)
                                    <div class="alert alert-warning" role="status">
                                        <div>
                                            <p class="font-semibold">You do not currently meet this city grant's requirements.</p>
                                            @if ($hasEligibilityFailures)
                                                <ul class="mt-2 list-inside list-disc space-y-1 text-sm">
                                                    @foreach ($nextEligibilityFailures as $failure)
                                                        <li>{{ $failure }}</li>
                                                    @endforeach
                                                </ul>
                                            @endif
                                        </div>
                                    </div>
                                @else
                                    <div class="alert alert-success" role="status">
                                        <span>You currently meet this city grant's eligibility requirements.</span>
                                    </div>
                                @endif
                            </section>
                        @endif

                        <form method="POST" action="{{ route('grants.city.request') }}" id="city-grant-request-form" class="grid grid-cols-1 gap-4 sm:grid-cols-4 sm:items-end">
                            @csrf
                            <input type="hidden" name="city_number" value="{{ $nextCity }}">

                            <x-form.error-summary
                                id="city-grant-request-errors"
                                class="sm:col-span-4"
                                title="We could not submit this city grant request."
                                :field-ids="[
                                    'account_id' => 'city-grant-account',
                                    'city_grant' => 'city-grant-account',
                                ]"
                                :only="['account_id', 'city_grant']"
                            />

                            <div class="sm:col-span-3">
                                <x-form.select
                                    id="city-grant-account"
                                    name="account_id"
                                    label="Bank account"
                                    :error-keys="['account_id', 'city_grant']"
                                    required
                                >
                                    <x-slot:help>City-grant funds will be deposited into this account after approval.</x-slot:help>
                                    @foreach ($accounts as $account)
                                        <option value="{{ $account->id }}" @selected((string) old('account_id') === (string) $account->id)>
                                            {{ $account->name }}
                                        </option>
                                    @endforeach
                                </x-form.select>
                            </div>

                            <button
                                type="submit"
                                class="btn btn-primary w-full"
                                @disabled($nextGrantAmount === null || $hasPendingRequest || $requirementsNotMet)
                            >
                                {{ $hasPendingRequest ? 'Request pending' : ($requirementsNotMet ? 'Requirements not met' : 'Request grant') }}
                            </button>
                        </form>

                        @if ($nextGrantAmount === null)
                            <div class="rounded-lg border border-warning/40 bg-warning/10 px-3 py-2 text-xs text-warning-content/80">
                                Grant requests are paused until city-cost data refreshes.
                            </div>
                        @endif

                        @if ($hasPendingRequest)
                            <div class="rounded-lg border border-warning/40 bg-warning/10 px-3 py-2 text-xs text-warning-content/80">
                                You already have a pending request for City #{{ $pendingRequest->city_number }}. Wait for a decision before submitting another.
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            <div class="xl:col-span-2">
                <div class="card h-full border border-base-300 bg-base-100 shadow-sm">
                    <div class="card-body space-y-3">
                        <h3 class="text-base font-bold text-base-content">Quick checklist</h3>
                        <p class="text-sm text-base-content/70">Check these before you submit.</p>
                        <ul class="space-y-2 text-sm text-base-content">
                            <li class="flex items-start gap-2">
                                <span class="mt-1 h-2 w-2 rounded-full bg-primary"></span>
                                <span>Submit only when you are ready to purchase City #{{ $nextCity }}.</span>
                            </li>
                            <li class="flex items-start gap-2">
                                <span class="mt-1 h-2 w-2 rounded-full bg-success"></span>
                                <span>Choose the correct account so disbursed funds land where you need them.</span>
                            </li>
                            <li class="flex items-start gap-2">
                                <span class="mt-1 h-2 w-2 rounded-full bg-info"></span>
                                <span>Review requirements in the roadmap cards before requesting.</span>
                            </li>
                            <li class="flex items-start gap-2">
                                <span class="mt-1 h-2 w-2 rounded-full bg-warning"></span>
                                <span>Only one pending city grant request may be active at a time.</span>
                            </li>
                        </ul>
                        <div class="divider my-1"></div>
                        <div class="grid grid-cols-3 gap-2 text-center">
                            <div class="rounded-lg bg-base-200 px-3 py-2">
                                <p class="text-xs nexus-text-muted">Approved</p>
                                <p class="font-bold text-success">{{ $approvedCount }}</p>
                            </div>
                            <div class="rounded-lg bg-base-200 px-3 py-2">
                                <p class="text-xs nexus-text-muted">Pending</p>
                                <p class="font-bold text-warning">{{ $pendingCount }}</p>
                            </div>
                            <div class="rounded-lg bg-base-200 px-3 py-2">
                                <p class="text-xs nexus-text-muted">Denied</p>
                                <p class="font-bold text-error">{{ $deniedCount }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="space-y-4">
            <div class="flex items-center justify-between gap-2">
                <h2 class="text-xl font-semibold text-base-content">Request history</h2>
                <span class="text-xs nexus-text-muted">Sorted by city then latest activity</span>
            </div>

            <x-card class="border border-base-300/70 bg-base-100/95">
                <x-slot:title>
                    <div>
                        Previous city grant requests
                        <div class="text-sm font-normal nexus-text-muted">Every submission with its final review status.</div>
                    </div>
                </x-slot:title>
                @if ($grantRequests->isEmpty())
                    <div class="flex items-center justify-center py-8 text-center text-base-content/70 text-sm">
                        <div>
                            <svg class="mx-auto mb-3 h-8 w-8 nexus-text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                      d="M13 16h-1v-4h-1m0-4h.01M12 6.25v.008m-.293 12.77a9 9 0 1112.586-12.586 9 9 0 01-12.586 12.586z" />
                            </svg>
                            You have not submitted any city grant requests yet.
                        </div>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="table table-zebra w-full text-sm" data-sortable="true">
                            <thead>
                                <tr>
                                    <th data-sortable="false">City #</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th data-sortable="false">Requested</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($grantRequests as $request)
                                    <tr>
                                        <td class="font-semibold">#{{ $request->city_number }}</td>
                                        <td>${{ number_format($request->grant_amount) }}</td>
                                        <td>
                                            @if ($request->status === 'pending')
                                                <span class="badge badge-warning badge-outline">Pending</span>
                                            @elseif ($request->status === 'approved')
                                                <span class="badge badge-success badge-outline">Approved</span>
                                            @else
                                                <span class="badge badge-error badge-outline">Denied</span>
                                            @endif
                                        </td>
                                        <td>{{ $request->created_at->format('M d, Y') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-card>
        </section>

        <section class="space-y-4">
            <div class="flex flex-col gap-1">
                <h2 class="text-xl font-semibold text-base-content">City Grant Roadmap</h2>
                <p class="text-sm text-base-content/70">Displayed in city number order so you can plan each expansion step.</p>
            </div>

            <div class="grid grid-cols-1 gap-5 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($grants as $grant)
                    @php
                        $computedAmount = $grantAmounts[$grant->id] ?? null;
                        $isCurrent = $grant->city_number === $nextCity;
                        $isPast = $grant->city_number < $nextCity;
                        $requirementSummary = collect($grant->requirement_summary ?? [])->values()->all();
                        $visibleRequirementSummary = array_slice($requirementSummary, 0, 3);
                        $remainingRequirementSummary = array_slice($requirementSummary, 3);
                    @endphp

                    <article @class([
                        'rounded-lg border bg-base-100/95 p-5 shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md',
                        'border-primary/40 bg-primary/10 ring-1 ring-primary/20' => $isCurrent,
                        'border-success/30 bg-success/10' => $isPast,
                        'border-base-300 bg-base-100' => ! $isCurrent && ! $isPast,
                    ])>
                        <div class="space-y-4">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <h3 class="text-lg font-bold text-base-content">City #{{ $grant->city_number }}</h3>
                                    <p class="text-xs uppercase tracking-wide nexus-text-muted">Grant Step {{ $grant->city_number }}</p>
                                </div>
                                @if ($isCurrent)
                                    @if (! $requirementsNotMet)
                                        <span class="badge badge-primary badge-outline">Eligible now</span>
                                    @else
                                        <span class="badge badge-warning badge-outline">Requirements not met</span>
                                    @endif
                                @elseif ($isPast)
                                    <span class="badge badge-success badge-outline">Passed</span>
                                @else
                                    <span class="badge badge-ghost">Upcoming</span>
                                @endif
                            </div>

                            <p class="text-sm text-base-content/80">{{ $grant->description }}</p>

                            <div class="rounded-lg border border-base-300 bg-base-200/60 px-3 py-2">
                                <p class="text-[11px] uppercase tracking-wide nexus-text-muted">Estimated grant amount</p>
                                <p class="text-xl font-extrabold text-base-content">
                                    {{ $computedAmount !== null ? '$' . number_format($computedAmount) : 'Unavailable' }}
                                </p>
                                <p class="text-xs text-base-content/65">{{ number_format($grant->grant_amount) }}% of projected city cost</p>
                            </div>

                            <div class="flex flex-wrap items-center gap-2" aria-label="City grant requirements">
                                @forelse ($visibleRequirementSummary as $summary)
                                    <span class="badge badge-ghost h-auto min-h-6 whitespace-normal py-1 text-left text-xs">{{ $summary }}</span>
                                @empty
                                    <span class="badge badge-outline">No custom requirements</span>
                                @endforelse

                                @if ($remainingRequirementSummary !== [])
                                    <div
                                        class="tooltip tooltip-info"
                                        data-tip="{{ implode(' • ', $remainingRequirementSummary) }}"
                                        tabindex="0"
                                        aria-label="{{ count($remainingRequirementSummary) }} more requirements: {{ implode('; ', $remainingRequirementSummary) }}"
                                    >
                                        <span class="badge badge-neutral">+{{ count($remainingRequirementSummary) }} more</span>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>
        </section>
    </div>
@endsection
