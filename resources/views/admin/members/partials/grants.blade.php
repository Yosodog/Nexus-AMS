@if ($requests->isEmpty())
    <p class="text-muted">No recent grant requests.</p>
@else
    <table class="table table-sm table-striped">
        <thead>
        <tr>
            <th>Grant</th>
            <th>Status</th>
            <th>Requested</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($requests as $request)
            <tr>
                <td>{{ $request->grant->name ?? 'N/A' }}</td>
                <td>
                    @if($request->approved_at)
                        <span class="badge bg-success">Approved</span>
                    @elseif($request->denied_at)
                        <span class="badge bg-danger">Denied</span>
                    @else
                        <span class="badge bg-warning text-dark">Pending</span>
                    @endif
                </td>
                <td>{{ $request->created_at->format('M d, Y') }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endif