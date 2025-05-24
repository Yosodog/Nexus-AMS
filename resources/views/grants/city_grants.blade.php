@extends('layouts.main')

@section("content")
    <div class="prose w-full max-w-none mb-6">
        <h1 class="text-center text-3xl font-extrabold text-primary">City Grants</h1>
    </div>

    {{-- City Grant Request Form --}}
    <div class="flex justify-center mb-16">
        <div class="w-full max-w-xl">
            <div class="card bg-base-100 border border-primary shadow-md">
                <div class="card-body text-base-content">
                    <h2 class="text-lg font-semibold mb-4">Apply for City #{{ Auth::user()->nation->num_cities + 1 }}</h2>

                    <form method="POST" action="{{ route('grants.city.request') }}" class="grid grid-cols-1 sm:grid-cols-3 gap-4 items-end">
                        @csrf
                        <input type="hidden" name="city_number" value="{{ Auth::user()->nation->num_cities + 1 }}">

                        <div class="form-control w-full col-span-2">
                            <label class="label">
                                <span class="label-text text-base-content">Bank Account</span>
                            </label>
                            <select name="account_id" class="select select-bordered w-full">
                                @foreach ($accounts as $account)
                                    <option value="{{ $account->id }}">{{ $account->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <button type="submit" class="btn btn-primary w-full">
                            Request Grant
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{-- Previous City Grant Requests --}}
    <div class="mb-16">
        <x-utils.card title="Previous City Grant Requests" extraClasses="shadow-md bg-base-100 text-base-content">
            @if ($grantRequests->isEmpty())
                <div class="flex items-center justify-center py-6 text-center text-base-content/70 text-sm">
                    <div>
                        <svg class="mx-auto w-8 h-8 mb-2 text-neutral" fill="none" stroke="currentColor" viewBox="0 0 24 24">
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
    <div class="mb-24">
        <h2 class="text-xl font-semibold mb-4 text-center text-base-content">Available Grants</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
            @foreach ($grants as $grant)
                @php
                    $eligibleCity = Auth::user()->nation->num_cities + 1;
                    $isEligible = $grant->city_number === $eligibleCity;
                    $isDisabled = !$grant->enabled;
                @endphp

                <div class="relative card transition transform hover:scale-[1.01]
                {{ $isDisabled ? 'border border-base-300 opacity-40 blur-[1px] pointer-events-none' :
                ($isEligible ? 'border border-primary bg-primary/10 shadow-lg ring-1 ring-primary/30' : 'border border-base-300 shadow-sm hover:shadow-xl') }}">
                    @if ($isDisabled)
                        <div class="absolute top-2 right-2">
                            <span class="badge badge-neutral text-xs">Disabled</span>
                        </div>
                    @endif

                    <div class="card-body text-base-content space-y-3">
                        <h3 class="text-lg font-bold text-primary">
                            City Grant #{{ $grant->city_number }}
                        </h3>

                        <p class="text-sm">{{ $grant->description }}</p>

                        <div class="mt-2">
                            <div class="text-2xl font-extrabold text-primary">
                                ${{ number_format($grant->grant_amount) }}
                            </div>
                            <div class="text-xs text-base-content/70 uppercase tracking-wide">
                                Grant Amount
                            </div>
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
@endsection