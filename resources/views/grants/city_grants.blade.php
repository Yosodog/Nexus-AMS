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

        $pendingRequest = $grantRequests->firstWhere('status', 'pending');
        $hasPendingRequest = $pendingRequest !== null;

        $grantsCompletedCount = $grants->where('city_number', '<', $nextCity)->count();
        $grantsTotalCount = $grants->count();
        $progressPercent = $grantsTotalCount > 0
            ? min(100, (int) round(($grantsCompletedCount / $grantsTotalCount) * 100))
            : 0;
    @endphp

    <div class="mx-auto space-y-8 lg:space-y-10">
        <section class="relative overflow-hidden rounded-2xl border border-primary/20 bg-gradient-to-br from-base-100 via-base-100 to-primary/10 p-6 shadow-lg lg:p-8">
            <div class="pointer-events-none absolute -right-12 -top-16 h-44 w-44 rounded-full bg-primary/10 blur-2xl"></div>
            <div class="pointer-events-none absolute -bottom-20 -left-12 h-52 w-52 rounded-full bg-info/10 blur-2xl"></div>

            <div class="relative flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
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
                            <p class="text-xs text-base-content/60">Updated {{ $cityAverageUpdatedAt->diffForHumans() }}</p>
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
                                <h2 class="text-xl font-bold text-base-content">Request City #{{ $nextCity }} Grant</h2>
                                <p class="text-sm text-base-content/70">
                                    Select a destination account and submit for economics review.
                                </p>
                            </div>
                            <span @class([
                                'badge badge-outline text-xs',
                                'badge-warning' => $hasPendingRequest,
                                'badge-primary' => ! $hasPendingRequest,
                            ])>
                                {{ $hasPendingRequest ? 'Already pending' : 'Ready to submit' }}
                            </span>
                        </div>

                        @if ($nextGrant)
                            <div class="grid gap-3 rounded-xl border border-base-300 bg-base-200/60 p-4 sm:grid-cols-3">
                                <div>
                                    <p class="text-xs uppercase tracking-wide text-base-content/60">Grant step</p>
                                    <p class="text-base font-bold text-base-content">City #{{ $nextGrant->city_number }}</p>
                                </div>
                                <div>
                                    <p class="text-xs uppercase tracking-wide text-base-content/60">Coverage</p>
                                    <p class="text-base font-bold text-base-content">{{ number_format($nextGrant->grant_amount) }}%</p>
                                </div>
                                <div>
                                    <p class="text-xs uppercase tracking-wide text-base-content/60">Estimated amount</p>
                                    <p @class([
                                        'text-base font-bold',
                                        'text-base-content' => $nextGrantAmount !== null,
                                        'text-warning' => $nextGrantAmount === null,
                                    ])>
                                        {{ $nextGrantAmount !== null ? '$' . number_format($nextGrantAmount) : 'Unavailable' }}
                                    </p>
                                </div>
                            </div>
                        @endif

                        <form method="POST" action="{{ route('grants.city.request') }}" class="grid grid-cols-1 gap-4 sm:grid-cols-4 sm:items-end">
                            @csrf
                            <input type="hidden" name="city_number" value="{{ $nextCity }}">

                            <div class="form-control sm:col-span-3">
                                <label class="label">
                                    <span class="label-text text-base-content">Bank account</span>
                                    <span class="label-text-alt text-base-content/60">Funds are deposited here</span>
                                </label>
                                <select name="account_id" class="select select-bordered w-full" required>
                                    @foreach ($accounts as $account)
                                        <option value="{{ $account->id }}" @selected((string) old('account_id') === (string) $account->id)>
                                            {{ $account->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            <button
                                type="submit"
                                class="btn btn-primary w-full"
                                @disabled($nextGrantAmount === null || $hasPendingRequest)
                            >
                                {{ $hasPendingRequest ? 'Request Pending' : 'Request Grant' }}
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
                        <h3 class="text-base font-bold text-base-content">Quick Checklist</h3>
                        <p class="text-sm text-base-content/70">Fast validation before you submit.</p>
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
                                <p class="text-xs text-base-content/60">Approved</p>
                                <p class="font-bold text-success">{{ $approvedCount }}</p>
                            </div>
                            <div class="rounded-lg bg-base-200 px-3 py-2">
                                <p class="text-xs text-base-content/60">Pending</p>
                                <p class="font-bold text-warning">{{ $pendingCount }}</p>
                            </div>
                            <div class="rounded-lg bg-base-200 px-3 py-2">
                                <p class="text-xs text-base-content/60">Denied</p>
                                <p class="font-bold text-error">{{ $deniedCount }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="space-y-4">
            <div class="flex items-center justify-between gap-2">
                <h2 class="text-xl font-semibold text-base-content">Request History</h2>
                <span class="text-xs text-base-content/60">Sorted by city then latest activity</span>
            </div>

            <x-utils.card title="Previous City Grant Requests" extraClasses="shadow-sm bg-base-100 text-base-content">
                @if ($grantRequests->isEmpty())
                    <div class="flex items-center justify-center py-8 text-center text-base-content/70 text-sm">
                        <div>
                            <svg class="mx-auto mb-3 h-8 w-8 text-base-content/50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                      d="M13 16h-1v-4h-1m0-4h.01M12 6.25v.008m-.293 12.77a9 9 0 1112.586-12.586 9 9 0 01-12.586 12.586z" />
                            </svg>
                            You have not submitted any city grant requests yet.
                        </div>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="table table-zebra w-full text-sm">
                            <thead>
                                <tr>
                                    <th>City #</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Requested</th>
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
            </x-utils.card>
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
                    @endphp

                    <article @class([
                        'card border bg-base-100 shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md',
                        'border-primary/30 bg-primary/5 ring-1 ring-primary/20' => $isCurrent,
                        'border-success/30 bg-success/5' => $isPast,
                        'border-base-300' => ! $isCurrent && ! $isPast,
                    ])>
                        <div class="card-body gap-4">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <h3 class="text-lg font-bold text-base-content">City #{{ $grant->city_number }}</h3>
                                    <p class="text-xs uppercase tracking-wide text-base-content/60">Grant Step {{ $grant->city_number }}</p>
                                </div>
                                @if ($isCurrent)
                                    <span class="badge badge-primary badge-outline">Eligible now</span>
                                @elseif ($isPast)
                                    <span class="badge badge-success badge-outline">Passed</span>
                                @else
                                    <span class="badge badge-ghost">Upcoming</span>
                                @endif
                            </div>

                            <p class="text-sm text-base-content/80">{{ $grant->description }}</p>

                            <div class="rounded-lg border border-base-300 bg-base-200/60 px-3 py-2">
                                <p class="text-[11px] uppercase tracking-wide text-base-content/60">Estimated grant amount</p>
                                <p class="text-xl font-extrabold text-base-content">
                                    {{ $computedAmount !== null ? '$' . number_format($computedAmount) : 'Unavailable' }}
                                </p>
                                <p class="text-xs text-base-content/65">{{ number_format($grant->grant_amount) }}% of projected city cost</p>
                            </div>

                            <div class="flex flex-wrap items-center gap-2">
                                @if (!empty($grant->requirements['required_projects']))
                                    <div class="tooltip tooltip-info" data-tip="{{ implode(', ', $grant->requirements['required_projects']) }}">
                                        <span class="badge badge-info badge-outline">Projects required</span>
                                    </div>
                                @endif

                                @if (!empty($grant->requirements['minimum_infra_per_city']))
                                    <div class="tooltip tooltip-info" data-tip="{{ number_format((float) $grant->requirements['minimum_infra_per_city']) }}">
                                        <span class="badge badge-warning badge-outline">Min infra / city</span>
                                    </div>
                                @endif

                                @if (empty($grant->requirements['required_projects']) && empty($grant->requirements['minimum_infra_per_city']))
                                    <span class="badge badge-outline">No extra requirements</span>
                                @endif
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>
        </section>
    </div>
@endsection
