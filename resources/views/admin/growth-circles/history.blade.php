@extends('layouts.admin')

@section('content')
    <div class="space-y-6">
        @can('view-growth-circles')
            @php
                $resourceLabels = $resourceLabels ?? \App\Models\GrowthCircleDistribution::distributionResourceLabels();
            @endphp
            <x-card title="Growth Circles Distribution History">
                <form method="GET" action="{{ route('admin.growth-circles.history') }}" class="grid grid-cols-1 md:grid-cols-4 gap-3 mb-4">
                    <label class="block">
                        <span class="text-xs font-medium text-base-content/70">From</span>
                        <input type="date" name="from" value="{{ request('from') }}" class="input input-bordered input-sm w-full">
                    </label>
                    <label class="block">
                        <span class="text-xs font-medium text-base-content/70">To</span>
                        <input type="date" name="to" value="{{ request('to') }}" class="input input-bordered input-sm w-full">
                    </label>
                    <label class="block">
                        <span class="text-xs font-medium text-base-content/70">Nation ID</span>
                        <input type="number" name="nation_id" value="{{ request('nation_id') }}" class="input input-bordered input-sm w-full">
                    </label>
                    <label class="block">
                        <span class="text-xs font-medium text-base-content/70">Account ID</span>
                        <input type="number" name="account_id" value="{{ request('account_id') }}" class="input input-bordered input-sm w-full">
                    </label>
                    <div class="md:col-span-4 flex gap-2">
                        <x-button type="submit" label="Filter" class="btn-primary btn-sm" />
                        <a href="{{ route('admin.growth-circles.history') }}" class="btn btn-sm btn-ghost">Clear</a>
                        <a href="{{ route('admin.growth-circles.index') }}" class="btn btn-sm btn-outline ml-auto">← Back to enrollments</a>
                    </div>
                </form>

                @if ($rows->isEmpty())
                    <p class="text-base-content/60 text-sm">No distributions match the current filter.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="table table-sm w-full">
                            <thead>
                            <tr>
                                <th>Cycle</th>
                                <th>Nation</th>
                                <th>Account</th>
                                @foreach ($resourceLabels as $resource => $label)
                                    <th class="text-right">{{ $label }}</th>
                                @endforeach
                            </tr>
                            </thead>
                            <tbody>
                            @foreach ($rows as $row)
                                <tr>
                                    <td>{{ $row->cycle_date->toDateString() }}</td>
                                    <td>{{ $row->nation?->nation_name ?? '(deleted)' }}</td>
                                    <td>{{ $row->account?->name ?? '(deleted)' }}</td>
                                    @foreach ($resourceLabels as $resource => $label)
                                        <td class="text-right">{{ number_format($row->{$resource}, 2) }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-4">
                        {{ $rows->links() }}
                    </div>
                @endif
            </x-card>
        @endcan
    </div>
@endsection
