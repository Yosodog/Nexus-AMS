@extends('layouts.main')

@section("content")
    <div class="prose w-full max-w-none mb-5">
        <h1 class="text-center flex items-center justify-center gap-2">
            Loans
        </h1>
    </div>

    {{-- Loan Request Form --}}
    <div class="mt-8">
        <x-utils.card title="Apply for a Loan" extraClasses="shadow-lg">
            <form method="POST" action="{{ route('loans.apply') }}">
                @csrf

                <label class="label" for="amount">Loan Amount:</label>
                <input type="number" name="amount" id="amount" min="1" required
                       placeholder="Enter loan amount" class="input input-bordered w-full mb-4">

                <label class="label" for="account_id">Select Bank Account:</label>
                <select name="account_id" id="account_id" class="select select-bordered w-full mb-4">
                    @foreach ($accounts as $account)
                        <option value="{{ $account->id }}">{{ $account->name }}</option>
                    @endforeach
                </select>

                <label class="label" for="term_weeks">Loan Term (Weeks):</label>
                <input type="number" id="term_weeks" name="term_weeks" min="4" max="52" required
                       placeholder="Enter term length" class="input input-bordered w-full mb-4">

                <button type="submit" class="btn btn-primary w-full">
                    Apply for Loan
                </button>
            </form>
        </x-utils.card>
    </div>

    <hr class="mt-4 mb-4">

    {{-- Previous Loan Requests --}}
    <div>
        <x-utils.card title="Your Loan History" extraClasses="shadow-lg">
            @if ($loans->isEmpty())
                <p class="text-gray-500">No previous loan applications found.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="table table-zebra w-full">
                        <thead>
                        <tr>
                            <th>Amount</th>
                            <th>Term (Weeks)</th>
                            <th>Status</th>
                            <th>Requested At</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($loans as $loan)
                            <tr>
                                <td>{{ number_format($loan->amount) }}</td>
                                <td>{{ $loan->term_weeks }}</td>
                                <td>
                                    @if ($loan->status === 'pending')
                                        <span class="badge badge-warning">Pending</span>
                                    @elseif ($loan->status === 'approved')
                                        <span class="badge badge-success">Approved</span>
                                    @else
                                        <span class="badge badge-error">Denied</span>
                                    @endif
                                </td>
                                <td>{{ $loan->created_at->format('M d, Y') }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-utils.card>
    </div>
@endsection