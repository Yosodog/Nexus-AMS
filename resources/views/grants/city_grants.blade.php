@extends('layouts.main')

@section("content")
    @php
        $nextCity = $nextCityNumber;
        $approvedCount = $grantRequests->where('status', 'approved')->count();
        $pendingCount = $grantRequests->where('status', 'pending')->count();
        $nextGrant = $grants->firstWhere('city_number', $nextCity);
        $nextGrantAmount = $nextGrant ? ($grantAmounts[$nextGrant->id] ?? null) : null;
    @endphp

    <div class="mx-auto space-y-10">
        <div class="rounded-2xl bg-base-100 border border-base-300 p-6 shadow-md">
            <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                <div>
                    <p class="text-xs uppercase tracking-wide text-base-content/60">Growth funding</p>
                    <h1 class="text-3xl font-extrabold text-primary">City Grants</h1>
                    <p class="text-sm text-base-content/70">Request funding for your next city and track approvals in one place.</p>
                </div>
                <div class="flex flex-wrap gap-3">
                    <div class="rounded-xl bg-primary/10 border border-primary/30 px-4 py-3 text-primary">
                        <p class="text-xs uppercase">Next city</p>
                        <p class="text-xl font-bold">#{{ $nextCity }}</p>
                    </div>
                    <div class="rounded-xl bg-base-200 border border-base-300 px-4 py-3">
                        <p class="text-xs uppercase text-base-content/70">Pending</p>
                        <p class="text-lg font-bold">{{ $pendingCount }}</p>
                    </div>
                    <div class="rounded-xl bg-base-200 border border-base-300 px-4 py-3">
                        <p class="text-xs uppercase text-base-content/70">Approved</p>
                        <p class="text-lg font-bold">{{ $approvedCount }}</p>
                    </div>
                </div>
            </div>
            <div class="mt-4 text-xs text-base-content/60">
                @if ($cityAverage !== null)
                    Top 20% average cities: {{ number_format($cityAverage, 2) }}
                    @if ($cityAverageUpdatedAt)
                        • Updated {{ $cityAverageUpdatedAt->diffForHumans() }}
                    @endif
                @else
                    <span class="text-warning">
                        City cost data is temporarily unavailable. Grant amounts will update once the API refreshes.
                    </span>
                @endif
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <div class="card bg-base-100 border border-primary shadow-md h-full">
                    <div class="card-body text-base-content space-y-4">
                        <div class="flex items-center justify-between">
                            <div>
                                <h2 class="text-lg font-semibold">Apply for City #{{ $nextCity }}</h2>
                                <p class="text-sm text-base-content/70">Pick the account for disbursement and submit your request.</p>
                            </div>
                            <span class="badge badge-primary badge-outline">Fresh application</span>
                        </div>
                        @if ($nextGrant)
                            <div class="flex flex-wrap items-center gap-2 text-sm text-base-content/70">
                                <span class="font-medium text-base-content">Estimated grant:</span>
                                @if ($nextGrantAmount !== null)
                                    ${{ number_format($nextGrantAmount) }}
                                    <span class="text-base-content/60">({{ number_format($nextGrant->grant_amount) }}%)</span>
                                @else
                                    <span class="text-warning">Unavailable</span>
                                @endif
                            </div>
                        @endif

                        <form method="POST" action="{{ route('grants.city.request') }}" class="grid grid-cols-1 sm:grid-cols-3 gap-4 items-end">
                            @csrf
                            <input type="hidden" name="city_number" value="{{ $nextCity }}">

                            <div class="form-control w-full col-span-2">
                                <label class="label">
                                    <span class="label-text text-base-content">Bank Account</span>
                                    <span class="label-text-alt text-base-content/60">Grant will be credited here</span>
                                </label>
                                <select name="account_id" class="select select-bordered w-full">
                                    @foreach ($accounts as $account)
                                        <option value="{{ $account->id }}">{{ $account->name }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <button type="submit" class="btn btn-primary w-full"
                                    {{ $nextGrantAmount === null ? 'disabled' : '' }}>
                                Request Grant
                            </button>
                        </form>
                        @if ($nextGrantAmount === null)
                            <p class="text-xs text-warning">
                                Grant requests are paused until city cost data refreshes.
                            </p>
                        @endif
                    </div>
                </div>
            </div>
            <div class="card bg-base-100 border border-base-300 shadow h-full">
                <div class="card-body space-y-3">
                    <h3 class="text-base font-semibold text-base-content">Grant checklist</h3>
                    <p class="text-sm text-base-content/70">Stay eligible by keeping your nation progress aligned with the next grant requirements.</p>
                    <ul class="space-y-2 text-sm text-base-content">
                        <li class="flex items-start gap-2">
                            <span class="mt-[2px] text-success">•</span>
                            <span>Request only when you are ready to build City #{{ $nextCity }}.</span>
                        </li>
                        <li class="flex items-start gap-2">
                            <span class="mt-[2px] text-primary">•</span>
                            <span>Funds are sent to the selected bank account for immediate use.</span>
                        </li>
                        <li class="flex items-start gap-2">
                            <span class="mt-[2px] text-warning">•</span>
                            <span>Review prior decisions below so you can avoid duplicate submissions.</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        {{-- Previous City Grant Requests --}}
        <div class="space-y-4">
            <x-utils.card title="Previous City Grant Requests" extraClasses="shadow-md bg-base-100 text-base-content">
                @if ($grantRequests->isEmpty())
                    <div class="flex items-center justify-center py-8 text-center text-base-content/70 text-sm">
                        <div>
                            <svg class="mx-auto w-8 h-8 mb-3 text-neutral" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                      d="M13 16h-1v-4h-1m0-4h.01M12 6.25v.008m-.293 12.77a9 9 0 1112.586-12.586 9 9 0 01-12.586 12.586z"/>
                            </svg>
                            You haven’t submitted any city grant requests yet.
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
        </div>

        {{-- City Grants List --}}
        <div class="space-y-4">
            <div class="flex flex-col gap-2">
                <h2 class="text-xl font-semibold text-base-content">Available Grants</h2>
                <p class="text-sm text-base-content/70">See what’s next and what prerequisites apply before you submit.</p>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                @foreach ($grants as $grant)
                    @php
                        $eligibleCity = $nextCity;
                        $isEligible = $grant->city_number === $eligibleCity;
                        $isDisabled = !$grant->enabled;
                    @endphp

                    <div class="relative card transition transform hover:translate-y-[-2px] hover:shadow-xl
                    {{ $isDisabled ? 'border border-base-300 opacity-60' :
                    ($isEligible ? 'border border-primary bg-primary/5 ring-1 ring-primary/30' : 'border border-base-300 shadow-sm') }}">
                        @if ($isEligible && !$isDisabled)
                            <div class="absolute top-2 right-2">
                                <span class="badge badge-success badge-outline text-xs">Eligible now</span>
                            </div>
                        @elseif ($isDisabled)
                            <div class="absolute top-2 right-2">
                                <span class="badge badge-neutral text-xs">Disabled</span>
                            </div>
                        @endif

                        <div class="card-body text-base-content space-y-3">
                            <div class="flex items-center justify-between">
                                <h3 class="text-lg font-bold text-primary">
                                    City Grant #{{ $grant->city_number }}
                                </h3>
                                <span class="badge badge-outline">Step {{ $grant->city_number }}</span>
                            </div>

                            <p class="text-sm">{{ $grant->description }}</p>

                            <div class="flex items-center justify-between">
                                <div>
                                    <div class="text-2xl font-extrabold text-primary">
                                        @php
                                            $computedAmount = $grantAmounts[$grant->id] ?? null;
                                        @endphp
                                        @if ($computedAmount !== null)
                                            ${{ number_format($computedAmount) }}
                                        @else
                                            Unavailable
                                        @endif
                                    </div>
                                    <div class="text-xs text-base-content/70 uppercase tracking-wide">
                                        Grant Amount ({{ number_format($grant->grant_amount) }}%)
                                    </div>
                                </div>
                                @if ($isEligible && !$isDisabled)
                                    <span class="badge badge-success badge-outline">Next up</span>
                                @elseif($isDisabled)
                                    <span class="badge badge-neutral badge-outline">Unavailable</span>
                                @else
                                    <span class="badge badge-ghost">Future</span>
                                @endif
                            </div>

                            @if (!empty($grant->requirements['required_projects']))
                                <div class="flex items-center gap-2 text-sm">
                                    <span class="font-medium">Required Projects</span>
                                    <div class="tooltip tooltip-info" data-tip="{{ implode(', ', $grant->requirements['required_projects']) }}">
                                        <button class="btn btn-xs btn-circle btn-ghost bg-info text-info-content">
                                            ?
                                        </button>
                                    </div>
                                </div>
                            @endif

                            @if (!empty($grant->requirements['minimum_infra_per_city']))
                                <div class="flex items-center gap-2 text-sm">
                                    <span class="font-medium">Min Infra/City</span>
                                    <div class="tooltip tooltip-info" data-tip="{{ $grant->requirements['minimum_infra_per_city'] }}">
                                        <button class="btn btn-xs btn-circle btn-ghost bg-info text-info-content">
                                            ?
                                        </button>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
@endsection
