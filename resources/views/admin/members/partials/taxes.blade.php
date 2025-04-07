@php use Carbon\Carbon; @endphp
@if ($taxes->isEmpty())
    <p class="text-muted">No recent taxes paid.</p>
@else
    <table class="table table-sm table-striped">
        <thead>
        <tr>
            <th>Date</th>
            <th>Money</th>
            <th>Steel</th>
            <th>Munitions</th>
            <th>Food</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($taxes as $tax)
            <tr>
                <td>{{ Carbon::parse($tax['date'])->format('M d, Y') }}</td>
                <td>${{ number_format($tax['money']) }}</td>
                <td>{{ number_format($tax['steel']) }}</td>
                <td>{{ number_format($tax['munitions']) }}</td>
                <td>{{ number_format($tax['food']) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endif