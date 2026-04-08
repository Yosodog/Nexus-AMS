@if ($requests->isEmpty())
    <p class="text-base-content/50 text-sm">No recent city grant requests.</p>
@else
    <table class="table table-sm table-zebra">
        <thead>
            <tr class="text-base-content/60">
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
                            <x-badge  value="Approved" class="badge-success badge-sm" />
                        @elseif($request->denied_at)
                            <x-badge  value="Denied" class="badge-error badge-sm" />
                        @else
                            <x-badge  value="Pending" class="badge-warning badge-sm" />
                        @endif
                    </td>
                    <td>{{ $request->created_at->format('M d, Y') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
