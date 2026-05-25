@php
    $isEnrolled = $gcEnrollment !== null;
    $isPaused = $isEnrolled && ! ($gcEligibility['eligible'] ?? true);
    $isEligible = (bool) ($gcEligibility['eligible'] ?? false);
    $lastDistribution = $gcRecentDistributions->first();
@endphp

<x-utils.card title="Growth Circles" extraClasses="space-y-3">
    @if ($isEnrolled && ! $isPaused)
        {{-- Active enrollment --}}
        <div class="rounded-xl bg-success/10 border border-success/30 p-4">
            <p class="text-success font-semibold">
                Enrolled — depositing into
                <span class="font-bold">{{ $gcEnrollment->account?->name ?? '(deleted account)' }}</span>.
            </p>
        </div>

        @if ($lastDistribution)
            <p class="text-sm">
                <span class="font-semibold">Last distribution:</span>
                {{ $lastDistribution->cycle_date->toDateString() }} —
                {{ number_format($lastDistribution->food, 2) }} food,
                {{ number_format($lastDistribution->uranium, 2) }} uranium
            </p>
        @else
            <p class="text-sm text-base-content/60">No distributions yet.</p>
        @endif

        @if ($gcRecentDistributions->isNotEmpty())
            <details class="rounded-lg border border-base-300 p-3">
                <summary class="cursor-pointer text-sm font-medium">Recent distributions (last 7 cycles)</summary>
                <table class="table table-xs mt-2 w-full">
                    <thead>
                    <tr>
                        <th>Cycle</th>
                        <th class="text-right">Food</th>
                        <th class="text-right">Uranium</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($gcRecentDistributions as $row)
                        <tr>
                            <td>{{ $row->cycle_date->toDateString() }}</td>
                            <td class="text-right">{{ number_format($row->food, 2) }}</td>
                            <td class="text-right">{{ number_format($row->uranium, 2) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </details>
        @endif

        <p class="text-xs text-base-content/60">Next distribution: tomorrow ~03:00 UTC.</p>

        <p class="text-xs text-base-content/60">Contact an admin if you need to leave the program.</p>

    @elseif ($isPaused)
        {{-- Enrolled but paused --}}
        <div class="rounded-xl bg-warning/10 border border-warning/40 p-4">
            <p class="mb-1 text-warning font-semibold">Paused — {{ $gcEligibility['reason'] }}</p>
            <p class="text-sm text-base-content/80">
                Distributions will resume automatically when this condition clears.
                You are still enrolled and depositing into
                <span class="font-bold">{{ $gcEnrollment->account?->name ?? '(deleted account)' }}</span>.
            </p>
        </div>

        @if ($lastDistribution)
            <p class="text-sm">
                <span class="font-semibold">Last distribution:</span>
                {{ $lastDistribution->cycle_date->toDateString() }} —
                {{ number_format($lastDistribution->food, 2) }} food,
                {{ number_format($lastDistribution->uranium, 2) }} uranium
            </p>
        @endif

        @if ($gcRecentDistributions->isNotEmpty())
            <details class="rounded-lg border border-base-300 p-3">
                <summary class="cursor-pointer text-sm font-medium">Recent distributions (last 7 cycles)</summary>
                <table class="table table-xs mt-2 w-full">
                    <thead>
                    <tr>
                        <th>Cycle</th>
                        <th class="text-right">Food</th>
                        <th class="text-right">Uranium</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($gcRecentDistributions as $row)
                        <tr>
                            <td>{{ $row->cycle_date->toDateString() }}</td>
                            <td class="text-right">{{ number_format($row->food, 2) }}</td>
                            <td class="text-right">{{ number_format($row->uranium, 2) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </details>
        @endif

        <p class="text-xs text-base-content/60">Contact an admin if you need to leave the program.</p>

    @else
        {{-- Not enrolled --}}
        <div class="rounded-xl bg-info/10 border border-info/30 p-4">
            <p class="mb-1 text-info font-semibold">Growth Circles</p>
            <p class="text-sm text-base-content/80">
                Contribute 100% of your tax income. In return, the alliance ships your daily food and uranium consumption to your selected account.
            </p>
        </div>

        @if ($isEligible)
            <p class="text-success text-sm">Eligible to enroll.</p>
        @else
            <div class="rounded-xl bg-error/10 border border-error/30 p-3">
                <p class="text-error text-sm">Not currently eligible: {{ $gcEligibility['reason'] }}</p>
            </div>
        @endif

        <form method="POST" action="{{ route('growth-circles.enroll') }}" class="space-y-3"
              @if (! $isEligible) onsubmit="return false;" @endif>
            @csrf
            <label class="label" for="gc_account_id">
                <span class="label-text">Choose an account for distributions:</span>
            </label>
            <select name="account_id" id="gc_account_id" class="select select-bordered w-full" required
                    @if (! $isEligible) disabled @endif>
                @foreach($accounts as $account)
                    <option value="{{ $account->id }}">{{ $account->name }}</option>
                @endforeach
            </select>

            @if ($ddEnrolled)
                <p class="text-xs text-base-content/70">
                    You are currently enrolled in DirectDeposit. Enrolling here will switch you over; your original pre-program tax bracket is preserved for restoration.
                </p>
                <button class="btn btn-primary w-full" type="submit"
                        @if (! $isEligible) disabled @endif
                        onclick="return confirm('This will disenroll you from DirectDeposit and enroll you in Growth Circles. Your tax bracket will change to the 100% Growth Circles bracket. Continue?');">
                    Switch from DirectDeposit
                </button>
            @else
                <button class="btn btn-primary w-full" type="submit"
                        @if (! $isEligible) disabled @endif>
                    Enroll in Growth Circles
                </button>
            @endif
        </form>
    @endif
</x-utils.card>
