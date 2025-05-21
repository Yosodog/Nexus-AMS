@props(['nation'])

<div class="card bg-base-100 shadow">
    <div class="card-body">
        <h3 class="card-title">Resources vs Required</h3>
        <div class="overflow-x-auto">
            <table class="table table-zebra">
                <thead>
                <tr>
                    <th>Resource</th>
                    <th>Current</th>
                    <th>Required</th>
                    <th>% Met</th>
                </tr>
                </thead>
                <tbody>
                @php
                    $resources = ['steel', 'aluminum', 'gasoline', 'munitions', 'uranium', 'food'];
                @endphp

                @foreach ($resources as $res)
                    @php
                        $current = $nation->$res ?? 0;
                        $required = 0; // MMR placeholder
                        $percent = $required > 0 ? round(($current / $required) * 100, 1) : 0;
                    @endphp
                    <tr>
                        <td class="capitalize">{{ $res }}</td>
                        <td>{{ number_format($current, 2) }}</td>
                        <td>{{ number_format($required, 2) }}</td>
                        <td>{{ $percent }}%</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>