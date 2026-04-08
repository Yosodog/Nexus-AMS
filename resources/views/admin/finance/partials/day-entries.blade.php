@php
    $formatCurrency = static fn (float $value): string => '$' . number_format($value, 2);
@endphp

@if ($entries->isEmpty())
    <p class="text-base-content/50 mb-0">No ledger entries for this day.</p>
@else
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead class="table-light">
            <tr>
                <th>Time</th>
                <th>Direction</th>
                <th>Category</th>
                <th>Description</th>
                <th class="text-right">Money</th>
                @foreach (['coal', 'oil', 'uranium', 'iron', 'bauxite', 'lead', 'gasoline', 'munitions', 'steel', 'aluminum', 'food'] as $resource)
                    <th class="text-right text-nowrap text-capitalize">{{ $resource }}</th>
                @endforeach
                <th>Nation</th>
                <th>Account</th>
                <th>Source</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($entries as $entry)
                @php
                    $category = $categories[$entry->category] ?? null;
                    $categoryColor = $category['color'] ?? 'secondary';
                    $source = $entry->resolvedSource();
                    $sourceLabel = $entry->source_type ? class_basename($entry->source_type) . ' #' . $entry->source_id : null;
                    $sourceLink = null;

                    if ($source instanceof \App\Models\GrantApplication) {
                        $sourceLink = route('admin.grants');
                    } elseif ($source instanceof \App\Models\CityGrantRequest) {
                        $sourceLink = route('admin.grants.city');
                    } elseif ($source instanceof \App\Models\WarAidRequest) {
                        $sourceLink = route('admin.war-aid');
                    } elseif ($source instanceof \App\Models\Taxes) {
                        $sourceLink = route('admin.taxes');
                    } elseif ($source instanceof \App\Models\LoanPayment && $source->loan_id) {
                        $sourceLink = route('admin.loans.view', ['Loan' => $source->loan_id]);
                    }
                @endphp
                <tr>
                    <td class="text-nowrap">{{ optional($entry->created_at)->format('H:i') ?? '-' }}</td>
                    <td>
                        <span class="badge text-bg-{{ $entry->isIncome() ? 'success' : 'danger' }}">
                            {{ ucfirst($entry->direction) }}
                        </span>
                    </td>
                    <td>
                        <span class="badge text-bg-{{ $categoryColor }}">
                            {{ $category['label'] ?? ucfirst($entry->category) }}
                        </span>
                    </td>
                    <td class="text-break" style="max-width: 220px;">{{ $entry->description ?? '-' }}</td>
                    <td class="text-right font-semibold">{{ $formatCurrency($entry->money) }}</td>
                    @foreach (['coal', 'oil', 'uranium', 'iron', 'bauxite', 'lead', 'gasoline', 'munitions', 'steel', 'aluminum', 'food'] as $resource)
                        <td class="text-right text-nowrap">{{ number_format($entry->$resource, 2) }}</td>
                    @endforeach
                    <td>
                        @if ($entry->nation_id)
                            <a href="https://politicsandwar.com/nation/id={{ $entry->nation_id }}" target="_blank" rel="noopener"
                               class="text-decoration-none">
                                {{ $entry->nation?->nation_name ?? 'Nation #'.$entry->nation_id }}
                            </a>
                        @else
                            <span class="text-base-content/50">-</span>
                        @endif
                    </td>
                    <td>{{ $entry->account?->name ?? '-' }}</td>
                    <td>
                        @if ($sourceLabel)
                            @if ($sourceLink)
                                <a href="{{ $sourceLink }}" class="badge bg-dark-subtle text-dark-emphasis text-decoration-none">
                                    {{ $sourceLabel }}
                                </a>
                            @else
                                <span class="badge bg-dark-subtle text-dark-emphasis">{{ $sourceLabel }}</span>
                            @endif
                        @else
                            <span class="text-base-content/50">-</span>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif
