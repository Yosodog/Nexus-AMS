@extends('layouts.admin')

@section('content')
    <x-header title="Alliance City Overview" separator use-h1>
        <x-slot:subtitle>Live roster of every city in the alliance umbrella, including offshore partners.</x-slot:subtitle>
    </x-header>

    <div class="mb-6 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <x-stat title="Total Cities" :value="number_format($summary['total_cities'])" icon="o-building-office-2" color="text-primary" description="Tracked city records" />
        @php
            $poweredPct = $summary['total_cities'] > 0
                ? round($summary['powered_cities'] / $summary['total_cities'] * 100, 1)
                : 0;
        @endphp
        <x-stat title="Powered Cities" :value="number_format($summary['powered_cities'])" icon="o-bolt" color="text-success" :description="$poweredPct . '% of all tracked cities'" />
        <x-stat title="Avg Infrastructure" :value="number_format($summary['average_infrastructure'], 2)" icon="o-arrow-trending-up" color="text-info" description="Average infra per city" />
        <x-stat title="Avg Land" :value="number_format($summary['average_land'], 2)" icon="o-map-pin" color="text-warning" description="Average land per city" />
    </div>

    <x-card>
        <x-slot:title>
            <div>
                Alliance Cities
                <div class="text-sm font-normal text-base-content/60">Sort the full roster by any column without loading every row at once.</div>
            </div>
        </x-slot:title>
        <x-slot:menu>
            <div class="flex flex-wrap gap-2 text-sm">
                <x-badge :value="'Unpowered (' . number_format($summary['unpowered_cities']) . ')'" class="badge-error badge-sm" />
                <x-badge :value="'Infra Off-Step (' . number_format($summary['misaligned_infrastructure']) . ')'" class="badge-warning badge-sm" />
                <x-badge :value="'Land Off-Step (' . number_format($summary['misaligned_land']) . ')'" class="badge-warning badge-outline badge-sm" />
            </div>
        </x-slot:menu>
        <div class="overflow-x-auto rounded-box border border-base-300">
            <table id="citiesTable" class="table table-zebra table-sm" style="width: 100%" data-sortable="false">
                <thead>
                <tr>
                    <x-admin.sortable-table-heading column="city" label="City" :current-sort="$sort" :current-direction="$direction" />
                    <x-admin.sortable-table-heading column="nation" label="Nation" :current-sort="$sort" :current-direction="$direction" />
                    <x-admin.sortable-table-heading column="alliance" label="Alliance" :current-sort="$sort" :current-direction="$direction" />
                    <x-admin.sortable-table-heading column="founded" label="Founded" :current-sort="$sort" :current-direction="$direction" />
                    <x-admin.sortable-table-heading column="infrastructure" label="Infrastructure" :current-sort="$sort" :current-direction="$direction" />
                    <x-admin.sortable-table-heading column="land" label="Land" :current-sort="$sort" :current-direction="$direction" />
                    <x-admin.sortable-table-heading column="power" label="Power" :current-sort="$sort" :current-direction="$direction" />
                    <x-admin.sortable-table-heading column="oil_power" label="Oil" :current-sort="$sort" :current-direction="$direction" />
                    <x-admin.sortable-table-heading column="wind_power" label="Wind" :current-sort="$sort" :current-direction="$direction" />
                    <x-admin.sortable-table-heading column="coal_power" label="Coal" :current-sort="$sort" :current-direction="$direction" />
                    <x-admin.sortable-table-heading column="nuclear_power" label="Nuclear" :current-sort="$sort" :current-direction="$direction" />
                    <x-admin.sortable-table-heading column="coal_mine" label="Coal Mine" :current-sort="$sort" :current-direction="$direction" />
                    <x-admin.sortable-table-heading column="oil_well" label="Oil Well" :current-sort="$sort" :current-direction="$direction" />
                    <x-admin.sortable-table-heading column="uranium_mine" label="Uranium Mine" :current-sort="$sort" :current-direction="$direction" />
                    <x-admin.sortable-table-heading column="lead_mine" label="Lead Mine" :current-sort="$sort" :current-direction="$direction" />
                    <x-admin.sortable-table-heading column="iron_mine" label="Iron Mine" :current-sort="$sort" :current-direction="$direction" />
                    <x-admin.sortable-table-heading column="bauxite_mine" label="Bauxite Mine" :current-sort="$sort" :current-direction="$direction" />
                    <x-admin.sortable-table-heading column="barracks" label="Barracks" :current-sort="$sort" :current-direction="$direction" />
                    <x-admin.sortable-table-heading column="farm" label="Farm" :current-sort="$sort" :current-direction="$direction" />
                    <x-admin.sortable-table-heading column="police_station" label="Police" :current-sort="$sort" :current-direction="$direction" />
                    <x-admin.sortable-table-heading column="hospital" label="Hospital" :current-sort="$sort" :current-direction="$direction" />
                    <x-admin.sortable-table-heading column="recycling_center" label="Recycling" :current-sort="$sort" :current-direction="$direction" />
                    <x-admin.sortable-table-heading column="subway" label="Subway" :current-sort="$sort" :current-direction="$direction" />
                    <x-admin.sortable-table-heading column="supermarket" label="Supermarket" :current-sort="$sort" :current-direction="$direction" />
                    <x-admin.sortable-table-heading column="bank" label="Bank" :current-sort="$sort" :current-direction="$direction" />
                    <x-admin.sortable-table-heading column="shopping_mall" label="Mall" :current-sort="$sort" :current-direction="$direction" />
                    <x-admin.sortable-table-heading column="stadium" label="Stadium" :current-sort="$sort" :current-direction="$direction" />
                    <x-admin.sortable-table-heading column="oil_refinery" label="Oil Refinery" :current-sort="$sort" :current-direction="$direction" />
                    <x-admin.sortable-table-heading column="aluminum_refinery" label="Aluminum" :current-sort="$sort" :current-direction="$direction" />
                    <x-admin.sortable-table-heading column="steel_mill" label="Steel" :current-sort="$sort" :current-direction="$direction" />
                    <x-admin.sortable-table-heading column="munitions_factory" label="Munitions" :current-sort="$sort" :current-direction="$direction" />
                    <x-admin.sortable-table-heading column="factory" label="Factory" :current-sort="$sort" :current-direction="$direction" />
                    <x-admin.sortable-table-heading column="hangar" label="Hangar" :current-sort="$sort" :current-direction="$direction" />
                    <x-admin.sortable-table-heading column="drydock" label="Drydock" :current-sort="$sort" :current-direction="$direction" />
                    <x-admin.sortable-table-heading column="updated" label="Last Updated" :current-sort="$sort" :current-direction="$direction" />
                </tr>
                </thead>
                <tbody>
                @foreach($cities as $city)
                    @php
                        $infraAligned = $city->isInfrastructureAligned();
                        $landAligned = $city->isLandAligned();
                        $nation = $city->nation;
                        $alliance = $nation?->alliance;
                    @endphp
                    <tr @class(['bg-error/10' => ! $city->powered])>
                        <td>
                            <a href="https://politicsandwar.com/city/id={{ $city->id }}"
                               target="_blank"
                               rel="noopener"
                               class="link link-primary font-semibold">
                                {{ $city->name }}
                            </a>
                        </td>
                        <td>
                            @if($nation)
                                <a href="https://politicsandwar.com/nation/id={{ $nation->id }}" target="_blank" rel="noopener" class="link link-hover">
                                    {{ $nation->leader_name }}
                                </a>
                            @else
                                <span class="text-base-content/50">Unknown</span>
                            @endif
                        </td>
                        <td>
                            @if($nation && $alliance)
                                <a href="https://politicsandwar.com/alliance/id={{ $alliance->id }}" target="_blank" rel="noopener" class="badge badge-ghost">
                                    {{ $alliance->name }}
                                </a>
                            @elseif($nation && $nation->alliance_id)
                                <span class="badge badge-ghost">#{{ $nation->alliance_id }}</span>
                            @else
                                <span class="text-base-content/50">&mdash;</span>
                            @endif
                        </td>
                        <td data-order="{{ optional($city->date)->format('Y-m-d') }}">{{ optional($city->date)->format('M j, Y') ?? '—' }}</td>
                        <td data-order="{{ $city->infrastructure }}" @class(['text-warning font-semibold' => ! $infraAligned])>
                            @if($infraAligned)
                                {{ number_format($city->infrastructure, 2) }}
                            @else
                                <span class="tooltip tooltip-left" data-tip="Infrastructure not divisible by 50">
                                    {{ number_format($city->infrastructure, 2) }}
                                </span>
                            @endif
                        </td>
                        <td data-order="{{ $city->land }}" @class(['text-warning font-semibold' => ! $landAligned])>
                            @if($landAligned)
                                {{ number_format($city->land, 2) }}
                            @else
                                <span class="tooltip tooltip-left" data-tip="Land not divisible by 50">
                                    {{ number_format($city->land, 2) }}
                                </span>
                            @endif
                        </td>
                        <td><x-badge :value="$city->powered ? 'Powered' : 'Offline'" :class="$city->powered ? 'badge-success badge-sm' : 'badge-error badge-sm'" /></td>
                        <td>{{ $city->oil_power }}</td>
                        <td>{{ $city->wind_power }}</td>
                        <td>{{ $city->coal_power }}</td>
                        <td>{{ $city->nuclear_power }}</td>
                        <td>{{ $city->coal_mine }}</td>
                        <td>{{ $city->oil_well }}</td>
                        <td>{{ $city->uranium_mine }}</td>
                        <td>{{ $city->lead_mine }}</td>
                        <td>{{ $city->iron_mine }}</td>
                        <td>{{ $city->bauxite_mine }}</td>
                        <td>{{ $city->barracks }}</td>
                        <td>{{ $city->farm }}</td>
                        <td>{{ $city->police_station }}</td>
                        <td>{{ $city->hospital }}</td>
                        <td>{{ $city->recycling_center }}</td>
                        <td>{{ $city->subway }}</td>
                        <td>{{ $city->supermarket }}</td>
                        <td>{{ $city->bank }}</td>
                        <td>{{ $city->shopping_mall }}</td>
                        <td>{{ $city->stadium }}</td>
                        <td>{{ $city->oil_refinery }}</td>
                        <td>{{ $city->aluminum_refinery }}</td>
                        <td>{{ $city->steel_mill }}</td>
                        <td>{{ $city->munitions_factory }}</td>
                        <td>{{ $city->factory }}</td>
                        <td>{{ $city->hangar }}</td>
                        <td>{{ $city->drydock }}</td>
                        <td data-order="{{ optional($city->updated_at)->timestamp ?? 0 }}">{{ optional($city->updated_at)->diffForHumans() ?? '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-sm text-base-content/60">
                Showing {{ number_format($cities->firstItem() ?? 0) }}–{{ number_format($cities->lastItem() ?? 0) }} of {{ number_format($summary['total_cities']) }} cities.
            </p>
            {{ $cities->onEachSide(1)->links() }}
        </div>
    </x-card>
@endsection
