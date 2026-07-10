@extends('layouts.admin')

@section('content')
    <div class="space-y-6">
        @can('view-growth-circles')
            @php
                $resourceLabels = $resourceLabels ?? \App\Models\GrowthCircleDistribution::distributionResourceLabels();
            @endphp

            {{-- Settings card --}}
            <x-card title="Growth Circles Settings">
                <form method="POST" action="{{ route('admin.growth-circles.settings') }}">
                    @csrf
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <label class="block space-y-2">
                            <span class="text-sm font-medium text-base-content">Growth Circles Tax ID</span>
                            <input
                                type="number"
                                name="growth_circles_tax_id"
                                value="{{ old('growth_circles_tax_id', $taxId) }}"
                                class="input w-full"
                                @cannot('manage-growth-circles') disabled @endcannot
                                required
                            >
                            <span class="block text-xs text-base-content/60">
                                The in-game tax bracket ID with 100% retention across all resources. Members enrolled in Growth Circles will be assigned this bracket.
                            </span>
                        </label>

                        <label class="block space-y-2">
                            <span class="text-sm font-medium text-base-content">Fallback Tax ID</span>
                            <input
                                type="number"
                                name="growth_circles_fallback_tax_id"
                                value="{{ old('growth_circles_fallback_tax_id', $fallbackTaxId) }}"
                                class="input w-full"
                                @cannot('manage-growth-circles') disabled @endcannot
                                required
                            >
                            <span class="block text-xs text-base-content/60">
                                Used when a member disenrolls and their original bracket cannot be restored. Both brackets must already exist in the P&W tax bracket list.
                            </span>
                        </label>
                    </div>
                    @can('manage-growth-circles')
                        <x-button label="Save Settings" type="submit" icon="o-check" class="btn-primary" />
                    @endcan
                </form>
            </x-card>

            {{-- Enrollments table --}}
            <x-card title="Enrollments">
                @if ($rows->isEmpty())
                    <p class="text-base-content/60 text-sm">No nations are currently enrolled in Growth Circles.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="table table-sm w-full" data-sortable="true">
                            <thead>
                            <tr>
                                <th>Nation</th>
                                <th class="text-right">Cities</th>
                                <th>Account</th>
                                <th>Enrolled</th>
                                <th>Last distribution</th>
                                @foreach ($resourceLabels as $resource => $label)
                                    <th class="text-right">7-day {{ strtolower($label) }}</th>
                                @endforeach
                                <th>Status</th>
                                @can('manage-growth-circles')
                                    <th class="text-right" data-sortable="false">Actions</th>
                                @endcan
                            </tr>
                            </thead>
                            <tbody>
                            @foreach ($rows as $row)
                                @php
                                    $enrollment = $row['enrollment'];
                                    $nation = $enrollment->nation;
                                    $eligibility = $row['eligibility'];
                                @endphp
                                <tr>
                                    <td>{{ $nation?->nation_name ?? '(deleted)' }}</td>
                                    <td class="text-right">{{ $nation?->num_cities ?? '—' }}</td>
                                    <td>{{ $enrollment->account?->name ?? '(deleted account)' }}</td>
                                    <td data-order="{{ $enrollment->enrolled_at?->timestamp ?? 0 }}">{{ $enrollment->enrolled_at?->toDateString() }}</td>
                                    <td data-order="{{ $row['last']?->cycle_date?->timestamp ?? 0 }}">
                                        @if ($row['last'])
                                            {{ $row['last']->cycle_date->toDateString() }}
                                            <span class="mt-1 flex flex-wrap gap-1 text-xs text-base-content/60">
                                                @foreach ($resourceLabels as $resource => $label)
                                                    <span>{{ $label }} {{ number_format($row['last']->{$resource}, 2) }}</span>
                                                @endforeach
                                            </span>
                                        @else
                                            <span class="text-base-content/50">—</span>
                                        @endif
                                    </td>
                                    @foreach ($resourceLabels as $resource => $label)
                                        <td class="text-right">{{ number_format($row['seven_day_resources'][$resource] ?? 0, 2) }}</td>
                                    @endforeach
                                    <td>
                                        @if ($eligibility['eligible'])
                                            <span class="badge badge-success badge-sm">Active</span>
                                        @else
                                            <span
                                                class="badge badge-warning badge-sm tooltip tooltip-left cursor-help"
                                                data-tip="{{ $eligibility['reason'] }}"
                                                tabindex="0"
                                                aria-label="Paused: {{ $eligibility['reason'] }}"
                                            >Paused</span>
                                            <span class="block text-xs text-base-content/60">{{ $eligibility['reason'] }}</span>
                                        @endif
                                    </td>
                                    @can('manage-growth-circles')
                                        <td class="text-right">
                                            <div class="flex justify-end gap-2">
                                                <form method="POST"
                                                      action="{{ route('admin.growth-circles.force-disenroll', $nation?->id ?? 0) }}"
                                                      data-confirm="Force-disenroll {{ $nation?->nation_name ?? 'this nation' }} from Growth Circles?"
                                                      data-confirm-title="Force disenrollment?"
                                                      data-confirm-label="Disenroll nation"
                                                      data-confirm-tone="error"
                                                      @if (! $nation) style="display:none" @endif>
                                                    @csrf
                                                    <button class="btn btn-xs btn-error">Force-disenroll</button>
                                                </form>
                                                @can('view-diagnostic-info')
                                                    <form method="POST"
                                                          action="{{ route('admin.growth-circles.reapply-bracket', $nation?->id ?? 0) }}"
                                                          @if (! $nation) style="display:none" @endif>
                                                        @csrf
                                                        <button class="btn btn-xs btn-outline">Reapply bracket</button>
                                                    </form>
                                                @endcan
                                            </div>
                                        </td>
                                    @endcan
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                <div class="mt-4 flex justify-end">
                    <a href="{{ route('admin.growth-circles.history') }}" class="link link-primary text-sm">
                        View distribution history →
                    </a>
                </div>
            </x-card>

        @endcan
    </div>
@endsection
