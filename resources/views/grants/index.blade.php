@extends('layouts.main')

@section("content")
    <div class="prose w-full max-w-none mb-5">
        <h1 class="text-center flex items-center justify-center gap-2">
            City Grants
        </h1>
    </div>

    {{-- City grant request form --}}
    <div class="mt-8">
        <x-utils.card title="Request City Grant" extraClasses="shadow-lg">
            <form method="POST" action="{{ route('grants.city.request') }}">
                @csrf
                <input type="hidden" name="city_number" value="{{ Auth::user()->nation->num_cities + 1 }}">

                <label class="label">Select Bank Account:</label>
                <select name="account_id" class="select select-bordered w-full mb-4">
                    @foreach ($accounts as $account)
                        <option value="{{ $account->id }}">{{ $account->name }}</option>
                    @endforeach
                </select>

                <button type="submit" class="btn btn-primary w-full">
                    Request City #{{ Auth::user()->nation->num_cities + 1 }}
                </button>
            </form>
        </x-utils.card>
    </div>

    <hr class="mt-4 mb-4">

    {{-- Previous City Grant Requests --}}
    <div>
        <x-utils.card title="Previous City Grant Requests" extraClasses="shadow-lg">
            @if ($grantRequests->isEmpty())
                <p class="text-gray-500">No previous city grant requests found.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="table table-zebra w-full">
                        <thead>
                        <tr>
                            <th>City #</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Requested At</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($grantRequests as $request)
                            <tr>
                                <td>{{ $request->city_number }}</td>
                                <td>{{ number_format($request->grant_amount) }}</td>
                                <td>
                                    @if ($request->status === 'pending')
                                        <span class="badge badge-warning">Pending</span>
                                    @elseif ($request->status === 'approved')
                                        <span class="badge badge-success">Approved</span>
                                    @else
                                        <span class="badge badge-error">Denied</span>
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

    <hr class="mt-4 mb-4">

    {{-- City Grants List --}}
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        @foreach ($grants as $grant)
            @php
                $isEligible = true; // Assume eligibility logic is checked in the controller
            @endphp
            <x-utils.card
                title="{{ $grant->name }}"
                extraClasses="shadow-lg border {{ $isEligible ? 'border-primary' : 'border-gray-300' }}">

                <p>{{ $grant->description }}</p>
                <p class="text-lg font-bold">💰 {{ number_format($grant->grant_amount) }} Money</p>
                <p>🏙️ City Slot: {{ $grant->city_number }}</p>

                @if (!empty($grant->requirements['required_projects']))
                    <p class="text-sm"><strong>Required Projects:</strong>
                        {{ implode(', ', $grant->requirements['required_projects']) }}
                    </p>
                @endif

                @if (!empty($grant->requirements['minimum_infra_per_city']))
                    <p class="text-sm"><strong>Min Infra Per City:</strong>
                        {{ $grant->requirements['minimum_infra_per_city'] }}
                    </p>
                @endif
            </x-utils.card>
        @endforeach
    </div>
@endsection
