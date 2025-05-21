@props(['nation'])

<div class="card bg-base-100 shadow">
    <div class="card-body">
        <h3 class="card-title">Military vs Required</h3>
        <div class="overflow-x-auto">
            <table class="table table-zebra">
                <thead>
                <tr>
                    <th>Unit</th>
                    <th>Current</th>
                    <th>Required</th>
                    <th>% Met</th>
                </tr>
                </thead>
                <tbody>
                @php
                    $units = ['soldiers', 'tanks', 'aircraft', 'ships', 'missiles', 'nukes'];
                @endphp

                @foreach ($units as $unit)
                    @php
                        $current = $nation->$unit ?? 0;
                        $required = 0; // Placeholder
                        $percent = $required > 0 ? round(($current / $required) * 100, 1) : 0;
                    @endphp
                    <tr>
                        <td class="capitalize">{{ $unit }}</td>
                        <td>{{ number_format($current) }}</td>
                        <td>{{ number_format($required) }}</td>
                        <td>{{ $percent }}%</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>