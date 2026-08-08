@if ($loans->isEmpty())
    <p class="nexus-text-muted text-sm">No recent loan requests.</p>
@else
    <div class="overflow-x-auto">
    <table class="table table-sm table-zebra" data-sortable="false">
        <thead>
            <tr class="nexus-text-muted">
                <th>Amount</th>
                <th>Status</th>
                <th>Requested At</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($loans as $loan)
                @php($loanStatusPresentation = $loan->statusPresentation())
                <tr>
                    <td>${{ number_format($loan->amount) }}</td>
                    <td>
                        <x-nexus-status
                            :label="$loanStatusPresentation['label']"
                            :intent="$loanStatusPresentation['intent']"
                            :icon="$loanStatusPresentation['icon']"
                        />
                    </td>
                    <td>{{ $loan->created_at->format('M d, Y') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    </div>
@endif
