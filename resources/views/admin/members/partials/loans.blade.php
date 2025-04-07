@if ($loans->isEmpty())
    <p class="text-muted">No recent loan requests.</p>
@else
    <table class="table table-sm table-striped">
        <thead>
        <tr>
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
                        <span class="badge bg-success">Approved</span>
                    @elseif($loan->denied_at)
                        <span class="badge bg-danger">Denied</span>
                    @else
                        <span class="badge bg-warning text-dark">Pending</span>
                    @endif
                </td>
                <td>{{ $loan->created_at->format('M d, Y') }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endif