@php
    $showDate = $showDate ?? false;
    $sortable = $sortable ?? false;
    $sort = $sort ?? 'date';
    $sortDirection = $sortDirection ?? 'desc';
    $sortUrls = $sortUrls ?? [];
    $formatCurrency = static fn (mixed $value): string => '$'.number_format(is_numeric($value) ? (float) $value : 0, 2);
    $sortLabel = static fn (string $column): string => $sort === $column
        ? ($sortDirection === 'asc' ? 'ascending' : 'descending')
        : 'none';
@endphp

<div class="overflow-x-auto rounded-box border border-base-300">
    <table class="table table-zebra table-sm" data-sortable="false">
        <thead>
        <tr>
            @if ($showDate)
                <th aria-sort="{{ $sortLabel('date') }}">
                    @if ($sortable)
                        <a href="{{ $sortUrls['date'] }}" class="inline-flex items-center gap-1 no-underline">
                            Date
                            <x-icon
                                :name="$sort === 'date' ? ($sortDirection === 'asc' ? 'o-chevron-up' : 'o-chevron-down') : 'o-arrows-up-down'"
                                class="size-3.5"
                                aria-hidden="true"
                            />
                            <span class="sr-only">
                                Sort by date {{ $sort === 'date' && $sortDirection === 'desc' ? 'ascending' : 'descending' }}
                            </span>
                        </a>
                    @else
                        Date
                    @endif
                </th>
            @endif
            <th>Time</th>
            <th>Direction</th>
            <th>Category</th>
            <th>Description</th>
            <th class="text-right" @if ($showDate) aria-sort="{{ $sortLabel('amount') }}" @endif>
                @if ($sortable)
                    <a href="{{ $sortUrls['amount'] }}" class="inline-flex items-center justify-end gap-1 no-underline">
                        Money
                        <x-icon
                            :name="$sort === 'amount' ? ($sortDirection === 'asc' ? 'o-chevron-up' : 'o-chevron-down') : 'o-arrows-up-down'"
                            class="size-3.5"
                            aria-hidden="true"
                        />
                        <span class="sr-only">
                            Sort by money amount {{ $sort === 'amount' && $sortDirection === 'desc' ? 'ascending' : 'descending' }}
                        </span>
                    </a>
                @else
                    Money
                @endif
            </th>
            @foreach (['coal', 'oil', 'uranium', 'iron', 'bauxite', 'lead', 'gasoline', 'munitions', 'steel', 'aluminum', 'food'] as $resource)
                <th class="text-right text-nowrap capitalize">{{ $resource }}</th>
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
                $categoryBadgeClass = match ($categoryColor) {
                    'success' => 'badge-success',
                    'danger' => 'badge-error',
                    'warning' => 'badge-warning',
                    'info' => 'badge-info',
                    default => 'badge-ghost',
                };
                $source = $entry->resolvedSource();
                $sourceLabel = $entry->source_type ? class_basename($entry->source_type).' #'.$entry->source_id : null;
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
                @if ($showDate)
                    <td class="text-nowrap">{{ $entry->date?->toFormattedDateString() ?? '-' }}</td>
                @endif
                <td class="text-nowrap">{{ optional($entry->created_at)->format('H:i') ?? '-' }}</td>
                <td>
                    <span class="badge {{ $entry->isIncome() ? 'badge-success' : 'badge-error' }}">
                        {{ ucfirst($entry->direction) }}
                    </span>
                </td>
                <td>
                    <span class="badge {{ $categoryBadgeClass }}">
                        {{ $category['label'] ?? ucfirst($entry->category) }}
                    </span>
                </td>
                <td class="max-w-56 break-words">{{ $entry->description ?? '-' }}</td>
                <td class="text-right font-semibold">{{ $formatCurrency($entry->money) }}</td>
                @foreach (['coal', 'oil', 'uranium', 'iron', 'bauxite', 'lead', 'gasoline', 'munitions', 'steel', 'aluminum', 'food'] as $resource)
                    <td class="text-right text-nowrap">{{ number_format($entry->$resource, 2) }}</td>
                @endforeach
                <td>
                    @if ($entry->nation_id)
                        <a
                            href="https://politicsandwar.com/nation/id={{ $entry->nation_id }}"
                            target="_blank"
                            rel="noopener"
                            class="no-underline"
                        >
                            {{ $entry->nation?->nation_name ?? 'Nation #'.$entry->nation_id }}
                        </a>
                    @else
                        <span class="nexus-text-muted">-</span>
                    @endif
                </td>
                <td>{{ $entry->account?->name ?? '-' }}</td>
                <td>
                    @if ($sourceLabel)
                        @if ($sourceLink)
                            <a href="{{ $sourceLink }}" class="badge badge-outline badge-neutral no-underline">
                                {{ $sourceLabel }}
                            </a>
                        @else
                            <span class="badge badge-outline badge-neutral">{{ $sourceLabel }}</span>
                        @endif
                    @else
                        <span class="nexus-text-muted">-</span>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
