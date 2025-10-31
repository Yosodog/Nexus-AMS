@extends('layouts.admin')

@section('content')
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col-sm-7">
                    <h3 class="mb-0">Alliance City Overview</h3>
                    <p class="text-muted mb-0">Live roster of every city in the alliance umbrella, including offshore partners.</p>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-3 col-sm-6">
            <x-admin.info-box icon="bi bi-buildings" bgColor="text-bg-primary" title="Total Cities"
                              :value="number_format($summary['total_cities'])"/>
        </div>
        <div class="col-lg-3 col-sm-6">
            @php
                $poweredPct = $summary['total_cities'] > 0
                    ? round($summary['powered_cities'] / $summary['total_cities'] * 100, 1)
                    : 0;
            @endphp
            <x-admin.info-box icon="bi bi-lightning-charge" bgColor="text-bg-success" title="Powered Cities"
                              :value="number_format($summary['powered_cities']) . ' (' . $poweredPct . '%)'"/>
        </div>
        <div class="col-lg-3 col-sm-6">
            <x-admin.info-box icon="bi bi-graph-up" bgColor="text-bg-info" title="Avg Infrastructure"
                              :value="number_format($summary['average_infrastructure'], 2)"/>
        </div>
        <div class="col-lg-3 col-sm-6">
            <x-admin.info-box icon="bi bi-geo" bgColor="text-bg-warning" title="Avg Land"
                              :value="number_format($summary['average_land'], 2)"/>
        </div>
    </div>

    <div class="card mt-4">
        <div class="card-header d-flex flex-column flex-lg-row gap-2 justify-content-between align-items-lg-center">
            <span class="fw-semibold">Alliance Cities</span>
            <div class="d-flex flex-wrap gap-2 small">
                <span class="badge rounded-pill bg-danger-subtle text-danger-emphasis border border-danger-subtle">
                    Unpowered ({{ number_format($summary['unpowered_cities']) }})
                </span>
                <span class="badge rounded-pill bg-warning-subtle text-warning-emphasis border border-warning-subtle">
                    Infra off-step ({{ number_format($summary['misaligned_infrastructure']) }})
                </span>
                <span class="badge rounded-pill bg-warning-subtle text-warning-emphasis border border-warning-subtle">
                    Land off-step ({{ number_format($summary['misaligned_land']) }})
                </span>
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table id="citiesTable" class="table table-hover table-striped align-middle table-sm" style="width: 100%">
                    <thead class="table-light">
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
                        <tr @class(['bg-danger-subtle text-danger-emphasis' => ! $city->powered])>
                            <td>
                                <a href="https://politicsandwar.com/city/id={{ $city->id }}"
                                   target="_blank"
                                   rel="noopener"
                                   class="fw-semibold link-underline-opacity-0 link-underline-opacity-75-hover">
                                    {{ $city->name }}
                                </a>
                            </td>
                            <td>
                                @if($nation)
                                    <a href="https://politicsandwar.com/nation/id={{ $nation->id }}" target="_blank"
                                       rel="noopener"
                                       class="link-underline-opacity-0 link-underline-opacity-75-hover">
                                        {{ $nation->leader_name }}
                                    </a>
                                @else
                                    <span class="text-muted">Unknown</span>
                                @endif
                            </td>
                            <td>
                                @if($nation && $alliance)
                                    <a href="https://politicsandwar.com/alliance/id={{ $alliance->id }}"
                                       target="_blank"
                                       rel="noopener"
                                       class="badge text-bg-secondary link-underline-opacity-0 link-underline-opacity-75-hover">
                                        {{ $alliance->name }}
                                    </a>
                                @elseif($nation && $nation->alliance_id)
                                    <span class="badge text-bg-secondary">#{{ $nation->alliance_id }}</span>
                                @else
                                    <span class="text-muted">&mdash;</span>
                                @endif
                            </td>
                            <td data-order="{{ optional($city->date)->format('Y-m-d') }}">
                                {{ optional($city->date)->format('M j, Y') ?? '—' }}
                            </td>
                            <td data-order="{{ $city->infrastructure }}"
                                @class(['bg-warning-subtle text-warning-emphasis fw-semibold' => ! $infraAligned])
                                @if(! $infraAligned) data-bs-toggle="tooltip" title="Infrastructure not divisible by 50" @endif>
                                {{ number_format($city->infrastructure, 2) }}
                            </td>
                            <td data-order="{{ $city->land }}"
                                @class(['bg-warning-subtle text-warning-emphasis fw-semibold' => ! $landAligned])
                                @if(! $landAligned) data-bs-toggle="tooltip" title="Land not divisible by 50" @endif>
                                {{ number_format($city->land, 2) }}
                            </td>
                            <td>
                                <span class="badge {{ $city->powered ? 'text-bg-success' : 'text-bg-danger' }}">
                                    {{ $city->powered ? 'Powered' : 'Offline' }}
                                </span>
                            </td>
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
                            <td data-order="{{ optional($city->updated_at)->timestamp ?? 0 }}">
                                {{ optional($city->updated_at)->diffForHumans() ?? '—' }}
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((el) => {
            new bootstrap.Tooltip(el);
        });

        new DataTable('#citiesTable', {
            pageLength: 50,
            scrollX: true,
            order: [[0, 'asc']],
        });
    </script>
@endpush
