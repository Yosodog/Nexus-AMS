@if ($requests->isEmpty())
    <p class="text-muted">No recent city grant requests.</p>
@else
    <table class="table table-sm table-striped">
        <thead>
        <tr>
            <th>City #</th>
            <th>Status</th>
            <th>Requested</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($requests as $request)
            <tr>
                <td>{{ $request->city_number }}</td>
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