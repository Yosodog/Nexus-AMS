@extends('layouts.admin')

@section('content')
    <x-header title="Alliance City Overview" separator>
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
                <div class="text-sm font-normal text-base-content/60">Sortable city roster with alignment and power flags.</div>
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
            <table id="citiesTable" class="table table-zebra table-sm" style="width: 100%">
                <thead>
                <tr>
                    <th>City</th>
                    <th>Nation</th>
                    <th>Alliance</th>
                    <th>Founded</th>
                    <th>Infrastructure</th>
                    <th>Land</th>
                    <th>Power</th>
                    <th>Oil</th>
                    <th>Wind</th>
                    <th>Coal</th>
                    <th>Nuclear</th>
                    <th>Coal Mine</th>
                    <th>Oil Well</th>
                    <th>Uranium Mine</th>
                    <th>Lead Mine</th>
                    <th>Iron Mine</th>
                    <th>Bauxite Mine</th>
                    <th>Barracks</th>
                    <th>Farm</th>
                    <th>Police</th>
                    <th>Hospital</th>
                    <th>Recycling</th>
                    <th>Subway</th>
                    <th>Supermarket</th>
                    <th>Bank</th>
                    <th>Mall</th>
                    <th>Stadium</th>
                    <th>Oil Refinery</th>
                    <th>Aluminum</th>
                    <th>Steel</th>
                    <th>Munitions</th>
                    <th>Factory</th>
                    <th>Hangar</th>
                    <th>Drydock</th>
                    <th>Last Updated</th>
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
                        <td data-order="{{ $city->infrastructure }}" @class(['text-warning font-semibold' => ! $infraAligned]) @if(! $infraAligned) data-bs-toggle="tooltip" title="Infrastructure not divisible by 50" @endif>
                            {{ number_format($city->infrastructure, 2) }}
                        </td>
                        <td data-order="{{ $city->land }}" @class(['text-warning font-semibold' => ! $landAligned]) @if(! $landAligned) data-bs-toggle="tooltip" title="Land not divisible by 50" @endif>
                            {{ number_format($city->land, 2) }}
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
    </x-card>
@endsection
