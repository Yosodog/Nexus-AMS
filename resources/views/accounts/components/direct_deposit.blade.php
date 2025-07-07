@php
    use App\Services\PWHelperService;

    $isEnrolled = isset($enrollment);
    $resources = PWHelperService::resources();
@endphp

<x-utils.card title="Direct Deposit">
    @if ($isEnrolled)
        <div class="mb-4">
            <p class="mb-2 text-success font-semibold">You are enrolled in Direct Deposit.</p>
            <p>Your deposits are going to: <span class="font-bold">{{ $enrollment->account->name }}</span></p>
        </div>

        <div class="mb-4">
            <h3 class="font-semibold mb-1">Your Current Tax Bracket ({{ $bracket->city_number }} Cities)</h3>
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
            <button class="btn btn-error" type="submit">Disenroll from Direct Deposit</button>
        </form>

        @include("accounts.components.mmr_assistant")
    @else
        <p class="mb-2 text-warning font-semibold">You are not currently enrolled in Direct Deposit.</p>

        <form method="POST" action="{{ route('dd.enroll') }}">
            @csrf
            <label class="label" for="account_id">
                <span class="label-text">Choose an account for deposits:</span>
            </label>
            <select name="account_id" id="account_id" class="select select-bordered w-full mb-4" required>
                @foreach($accounts as $account)
                    <option value="{{ $account->id }}">{{ $account->name }}</option>
                @endforeach
            </select>
            <button class="btn btn-primary" type="submit">Enroll in Direct Deposit</button>
        </form>
    @endif
</x-utils.card>