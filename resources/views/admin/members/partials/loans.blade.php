@if ($loans->isEmpty())
    <p class="text-base-content/50 text-sm">No recent loan requests.</p>
@else
    <table class="table table-sm table-zebra">
        <thead>
            <tr class="text-base-content/60">
                <th>Amount</th>
                <th>Status</th>
                <th>Requested At</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($loans as $loan)
                <tr>
                    <td>${{ number_format($loan->amount) }}</td>
                    <td>
                        @if($loan->approved_at)
                            <x-badge label="Approved" class="badge-success badge-sm" />
                        @elseif($loan->denied_at)
                            <x-badge label="Denied" class="badge-error badge-sm" />
                        @else
                            <x-badge label="Pending" class="badge-warning badge-sm" />
                        @endif
                    </td>
                    <td>{{ $loan->created_at->format('M d, Y') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
