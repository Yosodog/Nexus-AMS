@extends('layouts.main')

@php use Illuminate\Support\Str; @endphp

@section('content')
    <div class="rounded-2xl border border-base-300 bg-base-100 p-6 shadow mb-6">
        <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
            <div>
                <p class="text-xs uppercase tracking-wide text-base-content/60">Grant program</p>
                <h1 class="inline-flex items-center gap-2 bg-gradient-to-r from-primary to-accent bg-clip-text text-3xl font-bold text-transparent sm:text-4xl">
                    {{ ucwords($grant->name) }}
                </h1>
                <p class="text-sm text-base-content/70">Review the payout, eligibility requirements, and apply with your preferred account.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <span class="badge badge-outline">{{ $grant->is_one_time ? 'One-time' : 'Reusable' }}</span>
                @if (($eligibilityReport['passes'] ?? false) === true)
                    <span class="badge badge-success badge-outline">Eligible</span>
                @elseif (! empty($eligibilityReport['summary']))
                    <span class="badge badge-warning badge-outline">Requirements not met</span>
                @endif
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-[1.4fr,1fr]">
        <div class="space-y-6">
            <x-utils.card title="Grant Details" extraClasses="shadow-xl border border-base-300">
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                    @if ($grant->money > 0)
                        <div class="rounded-xl bg-base-200 p-4">
                            <div class="text-xs uppercase tracking-wide text-base-content/55">Money</div>
                            <div class="mt-2 text-xl font-semibold text-success">${{ number_format($grant->money) }}</div>
                        </div>
                    @endif

                    @foreach (['coal', 'oil', 'uranium', 'iron', 'bauxite', 'lead', 'gasoline', 'munitions', 'steel', 'aluminum', 'food'] as $resource)
                        @if ((int) $grant->$resource > 0)
                            <div class="rounded-xl bg-base-200 p-4">
                                <div class="text-xs uppercase tracking-wide text-base-content/55">{{ ucfirst($resource) }}</div>
                                <div class="mt-2 text-xl font-semibold text-success">{{ number_format($grant->$resource) }}</div>
                            </div>
                        @endif
                    @endforeach
                </div>

                <div class="mt-4">
                    <span class="badge badge-outline badge-lg">
                        {{ $grant->is_one_time ? 'One-time Grant' : 'Reusable Grant' }}
                    </span>
                </div>
            </x-utils.card>

            <x-utils.card title="Eligibility" extraClasses="shadow-xl border border-base-300">
                @if (! empty($eligibilityReport['summary']))
                    <div class="space-y-3">
                        @foreach ($eligibilityReport['summary'] as $summary)
                            <div class="flex items-start gap-3 rounded-xl border border-base-300 bg-base-200/70 px-4 py-3">
                                <span class="mt-0.5 text-primary">•</span>
                                <p class="text-sm text-base-content/80">{{ $summary }}</p>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="rounded-xl border border-dashed border-base-300 bg-base-200/60 px-4 py-4 text-sm text-base-content/70">
                        This grant does not have custom eligibility requirements beyond the standard alliance and application checks.
                    </div>
                @endif

                @if (! empty($eligibilityReport['failures']))
                    <div class="alert alert-warning mt-4">
                        <div>
                            <div class="font-semibold">You do not currently meet this grant's requirements.</div>
                            <ul class="mt-2 space-y-1 text-sm">
                                @foreach ($eligibilityReport['failures'] as $failure)
                                    <li>{{ $failure }}</li>
                                @endforeach
                            </ul>
                        </div>
                    </div>
                @elseif (! empty($eligibilityReport['summary']))
                    <div class="alert alert-success mt-4">
                        <span>You currently meet the custom eligibility requirements for this grant.</span>
                    </div>
                @endif
            </x-utils.card>
        </div>

        <div class="space-y-6">
            @if ($alreadyApplied)
                <div class="alert alert-info shadow-lg">
                    <span class="text-lg">You’ve already received this grant.</span>
                </div>
            @else
                <x-utils.card title="Apply for this Grant" extraClasses="shadow-xl border border-base-300">
                    <form method="POST" action="{{ route('grants.apply', $grant->slug) }}" id="apply-form" class="space-y-4">
                        @csrf

                        <div class="form-control w-full">
                            <label class="label font-semibold text-base-content" for="account_id">Select Bank Account</label>
                            <select name="account_id" id="account_id" class="select select-bordered w-full">
                                <option value="">-- Choose an account --</option>
                                @foreach ($accounts as $account)
                                    <option value="{{ $account->id }}">{{ $account->name }} (Balance: ${{ number_format($account->money) }})</option>
                                @endforeach
                            </select>
                            @error('account_id')
                                <span class="mt-1 text-sm text-error">{{ $message }}</span>
                            @enderror
                        </div>

                        <button type="submit" class="btn btn-primary w-full sm:w-auto" @disabled(! empty($eligibilityReport['failures']))>
                            Apply for Grant
                        </button>
                    </form>
                </x-utils.card>
            @endif

            @if (! empty($grant->description))
                <x-utils.card title="Grant Overview" extraClasses="bg-base-100 shadow border border-base-200">
                    <div class="prose max-w-none">
                        {!! Str::of($grant->description)->markdown([
                            'html_input' => 'strip',
                            'allow_unsafe_links' => false,
                        ]) !!}
                    </div>
                </x-utils.card>
            @endif
        </div>
    </div>
@endsection
