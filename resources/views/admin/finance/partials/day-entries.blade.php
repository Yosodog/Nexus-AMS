@if ($entries->isEmpty())
    <p class="nexus-text-muted mb-0">No ledger entries for this day match the current filters.</p>
@else
    @include('admin.finance.partials.entries-table', [
        'entries' => $entries,
        'categories' => $categories,
        'showDate' => false,
        'sortable' => false,
    ])
@endif
