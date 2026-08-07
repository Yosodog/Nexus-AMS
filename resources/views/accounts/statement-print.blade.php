<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Account statement — {{ $account->name }}</title>
    <style>
        body { color: #111827; font-family: ui-sans-serif, system-ui, sans-serif; font-size: 11px; line-height: 1.4; margin: 24px; }
        h1, h2 { margin: 0 0 8px; }
        h1 { font-size: 24px; }
        h2 { font-size: 15px; margin-top: 20px; }
        p { margin: 4px 0; }
        .notice { background: #eff6ff; border: 1px solid #93c5fd; padding: 10px; }
        .warning { background: #fffbeb; border: 1px solid #fbbf24; padding: 10px; }
        table { border-collapse: collapse; margin-top: 8px; width: 100%; }
        caption { font-weight: 600; margin-bottom: 6px; text-align: left; }
        th, td { border: 1px solid #d1d5db; padding: 4px; text-align: left; vertical-align: top; }
        th { background: #f3f4f6; }
        .nowrap { white-space: nowrap; }
        .numeric { text-align: right; white-space: nowrap; }
        .screen-note { font-size: 12px; margin-bottom: 16px; }
        @page { margin: 12mm; size: landscape; }
        @media print { .screen-note { display: none; } body { margin: 0; } thead { display: table-header-group; } tr { break-inside: avoid; } }
    </style>
</head>
<body>
<p class="screen-note">Use your browser’s print command to print or save this statement as PDF.</p>
<main aria-labelledby="print-statement-heading">
    <header>
        <h1 id="print-statement-heading">Personal account statement</h1>
        <p><strong>Account:</strong> {{ $account->name }} (ID {{ $account->id }})</p>
        <p><strong>Nation ID:</strong> {{ $account->nation_id }}</p>
        <p><strong>Period:</strong> {{ $filters['from'] }} through {{ $filters['to'] }}</p>
        <p><strong>Type:</strong> {{ $filters['type'] ? str($filters['type'])->headline() : 'All types' }}</p>
        <p><strong>Status:</strong> {{ $filters['status'] ? str($filters['status'])->headline() : 'All statuses' }}</p>
        <p><strong>Generated:</strong> {{ now()->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s T') }}</p>
    </header>

    <section class="notice" aria-labelledby="balance-availability-heading">
        <h2 id="balance-availability-heading">Opening and closing balances unavailable</h2>
        <p>
            Nexus does not retain authoritative historical balance snapshots for this account. Current observed balances are
            included for reference and must not be treated as the closing balance for this period.
        </p>
    </section>

    <section aria-labelledby="print-current-balances-heading">
        <h2 id="print-current-balances-heading">Current observed balances</h2>
        <table>
            <caption>Current observed resource balances, not period balances</caption>
            <thead>
            <tr>
                @foreach($resourceColumns as $resource)
                    <th scope="col">{{ ucfirst($resource) }}</th>
                @endforeach
            </tr>
            </thead>
            <tbody>
            <tr>
                @foreach($resourceColumns as $resource)
                    @php $amount = (float) $account->{$resource}; @endphp
                    <td class="numeric">
                        @if($resource === 'money')
                            {{ $amount < 0 ? '-$' : '$' }}{{ number_format(abs($amount), 2) }}
                        @else
                            {{ number_format($amount, 2) }}
                        @endif
                    </td>
                @endforeach
            </tr>
            </tbody>
        </table>
    </section>

    @if($truncated)
        <section class="warning" aria-labelledby="print-limit-heading">
            <h2 id="print-limit-heading">Print view shortened</h2>
            <p>This statement exceeds the safe print limit. Download the CSV for the complete filtered result.</p>
        </section>
    @endif

    <section aria-labelledby="print-activity-heading">
        <h2 id="print-activity-heading">Account activity</h2>
        <table>
            <caption>{{ number_format($rows->count()) }} displayed activity records</caption>
            <thead>
            <tr>
                <th scope="col">Date</th>
                <th scope="col">Type</th>
                <th scope="col">Status</th>
                <th scope="col">Direction</th>
                <th scope="col">Reference ID</th>
                @foreach($resourceColumns as $resource)
                    <th scope="col">{{ ucfirst($resource) }}</th>
                @endforeach
                <th scope="col">Description</th>
            </tr>
            </thead>
            <tbody>
            @forelse($rows as $row)
                <tr>
                    <td class="nowrap">{{ $row->occurredAt->setTimezone(config('app.timezone'))->format('Y-m-d H:i T') }}</td>
                    <td>{{ $row->typeLabel }}</td>
                    <td>{{ $row->statusLabel }}</td>
                    <td>{{ ucfirst($row->direction) }}</td>
                    <td class="nowrap">{{ $row->referenceId }}</td>
                    @foreach($resourceColumns as $resource)
                        @php $amount = $row->resources[$resource]; @endphp
                        <td class="numeric">
                            @if($resource === 'money')
                                {{ $amount < 0 ? '-$' : '$' }}{{ number_format(abs($amount), 2) }}
                            @else
                                {{ number_format($amount, 2) }}
                            @endif
                        </td>
                    @endforeach
                    <td>{{ $row->description ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="{{ 6 + count($resourceColumns) }}">No activity matches the selected filters.</td></tr>
            @endforelse
            </tbody>
        </table>
    </section>
</main>
</body>
</html>
