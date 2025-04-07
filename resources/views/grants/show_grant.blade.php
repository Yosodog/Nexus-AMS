@extends('layouts.main')

@php use Illuminate\Support\Str; @endphp

@section("content")
    <div class="prose w-full max-w-none mb-6 text-center">
        <h1 class="text-4xl font-bold bg-gradient-to-r from-primary to-accent bg-clip-text text-transparent inline-flex items-center justify-center gap-2">
            {{ ucwords($grant->name) }}
        </h1>
    </div>

    {{-- Grant Overview --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-10">
        <x-utils.card title="Grant Details" extraClasses="shadow-xl border border-base-300">
            <div class="space-y-3 text-base-content">
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-4 mt-4">
                    @if ($grant->money > 0)
                        <div class="bg-base-200 rounded-lg p-4">
                            <div class="text-sm text-gray-500">💰 Money</div>
                            <div class="text-xl font-semibold text-success">${{ number_format($grant->money) }}</div>
                        </div>
                    @endif
                    @foreach (['coal','oil','uranium','iron','bauxite','lead','gasoline','munitions','steel','aluminum','food'] as $resource)
                        @if ($grant->$resource > 0)
                            <div class="bg-base-200 rounded-lg p-4">
                                <div class="text-sm text-gray-500">{{ ucfirst($resource) }}</div>
                                <div class="text-xl font-semibold">{{ number_format($grant->$resource) }}</div>
                            </div>
                        @endif
                    @endforeach
                </div>

                <div class="mt-4">
                    <span class="badge badge-outline badge-lg">
                        {{ $grant->is_one_time ? 'One-time Grant' : 'Reusable Grant' }}
                    </span>
                </div>
            </div>
        </x-utils.card>

        {{-- Application Form --}}
        @if ($alreadyApplied)
            <div class="alert alert-info shadow-lg h-full flex items-center justify-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="stroke-current shrink-0 h-6 w-6"
                     fill="none" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M13 16h-1v-4h-1m1-4h.01M12 20a8 8 0 100-16 8 8 0 000 16z"/>
                </svg>
                <span class="ml-2 text-lg">You’ve already received this grant.</span>
            </div>
        @else
            <x-utils.card title="Apply for this Grant" extraClasses="shadow-xl border border-base-300">
                <form method="POST" action="{{ route('grants.apply', $grant->slug) }}" id="apply-form">
                    @csrf

                    <div class="form-control w-full mb-4">
                        <label class="label font-semibold text-base-content" for="account_id">Select Bank Account</label>
                        <select name="account_id" id="account_id" class="select select-bordered">
                            <option value="">-- Choose an account --</option>
                            @foreach ($accounts as $account)
                                <option value="{{ $account->id }}">{{ $account->name }} (Balance: ${{ number_format($account->money) }})</option>
                            @endforeach
                        </select>
                        @error('account_id')
                            <span class="text-sm text-error mt-1">{{ $message }}</span>
                        @enderror
                    </div>

                    <button type="submit" class="btn btn-primary btn-wide mt-2">
                        🎯 Apply for Grant
                    </button>
                </form>
            </x-utils.card>
        @endif
    </div>

    <div class="divider"></div>

    @if (!empty($grant->description))
        <x-utils.card title="📄 Grant Overview" extraClasses="bg-base-100 shadow border border-base-200 mt-8">
            <div class="prose max-w-none">
                {!! Str::of($grant->description)->markdown([
                    'html_input' => 'strip',
                    'allow_unsafe_links' => false,
                ]) !!}
            </div>
        </x-utils.card>
    @endif
@endsection