@php
    use App\Services\PWHelperService;

    $isEnrolled = isset($enrollment);
    $resources = PWHelperService::resources();
@endphp

<x-utils.card title="Direct Deposit" extraClasses="space-y-3">
    @if ($isEnrolled)
        <div class="rounded-xl bg-success/10 border border-success/30 p-4">
            <p class="mb-1 text-success font-semibold">Enrolled</p>
            <p class="text-sm text-base-content/80">Your deposits are heading to <span class="font-bold">{{ $enrollment->account->name }}</span>.</p>
        </div>

        <div class="mb-4 space-y-2">
            <div class="flex items-center justify-between">
                <h3 class="font-semibold">Current tax bracket</h3>
                <span class="badge badge-outline">City {{ $bracket->city_number }}</span>
            </div>
            <div class="overflow-x-auto">
                <table class="table table-sm w-full">
                    <thead>
                    <tr>
                        @foreach($resources as $r)
                            <th>{{ ucfirst($r) }}</th>
                        @endforeach
                    </tr>
                    </thead>
                    <tbody>
                    <tr>
                        @foreach($resources as $r)
                            <td>{{ number_format($bracket->$r, 2) }}%</td>
                        @endforeach
                    </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <form method="POST" action="{{ route('dd.disenroll') }}">
            @csrf
            <button class="btn btn-error w-full" type="submit">Disenroll from Direct Deposit</button>
        </form>

        @include("accounts.components.mmr_assistant")
    @else
        <div class="rounded-xl bg-warning/10 border border-warning/40 p-4">
            <p class="mb-1 text-warning font-semibold">Not enrolled</p>
            <p class="text-sm text-base-content/80">Enroll to automate deposits and unlock the MMR assistant.</p>
        </div>

        <form method="POST" action="{{ route('dd.enroll') }}" class="space-y-3">
            @csrf
            <label class="label" for="account_id">
                <span class="label-text">Choose an account for deposits:</span>
            </label>
            <select name="account_id" id="account_id" class="select select-bordered w-full mb-4" required>
                @foreach($accounts as $account)
                    <option value="{{ $account->id }}">{{ $account->name }}</option>
                @endforeach
            </select>
            <button class="btn btn-primary w-full" type="submit">Enroll in Direct Deposit</button>
        </form>
    @endif
</x-utils.card>
