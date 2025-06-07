@extends('layouts.admin')

@section('content')
    <div class="app-content-header mb-3">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center">
                <h3 class="mb-0">Minimum Military Requirements</h3>
                <form method="POST" action="{{ route('admin.mmr.store') }}" class="d-flex align-items-center gap-2">
                    @csrf
                    <input type="number" name="city_count" class="form-control form-control-sm" placeholder="City Count" min="1" required style="width:120px">
                    <button type="submit" class="btn btn-sm btn-primary">
                        <i class="bi bi-plus-circle me-1"></i> Add New Tier
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="app-content">
        <div class="container-fluid">
            @php
                $resourceFields = ['money', 'steel', 'aluminum', 'munitions', 'uranium', 'food', 'gasoline'];
                            $unitFields = ['soldiers', 'tanks', 'aircraft', 'ships', 'missiles', 'nukes', 'spies'];
            @endphp

            {{-- Section: Tier Definitions --}}
            <div class="mb-4">
                <h4 class="fw-semibold">Defined MMR Tiers</h4>
                <p class="text-muted mb-2">Each city count tier defines the minimum resource and unit expectations for members. Tier 0 is the fallback and cannot be deleted.</p>
            </div>

            <div class="card mb-5">
                <div class="card-header">
                    <h5 class="card-title mb-0">Tier Configuration</h5>
                </div>
                <div class="card-body table-responsive">
                    <form method="POST" action="{{ route('admin.mmr.updateAll') }}">
                        @csrf
                        <table class="table table-bordered table-striped align-middle table-hover">
                            <thead class="table-light">
                            <tr>
                                <th>City Count</th>
                                @foreach(array_merge($resourceFields, ['barracks', 'factories', 'hangars', 'drydocks', 'missiles', 'nukes', 'spies']) as $field)
                                    <th>{{ ucfirst($field) }}</th>
                                @endforeach
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($tiers as $tier)
                                <tr>
                                    <td class="fw-bold bg-light">{{ $tier->city_count }}</td>
                                    @foreach(array_merge($resourceFields, ['barracks', 'factories', 'hangars', 'drydocks', 'missiles', 'nukes', 'spies']) as $field)
                                        <td>
                                            <input type="number"
                                                   name="tiers[{{ $tier->id }}][{{ $field }}]"
                                                   value="{{ old("tiers.{$tier->id}.{$field}", $tier->$field) }}"
                                                   class="form-control form-control-sm @error("tiers.{$tier->id}.{$field}") is-invalid @enderror"
                                                   min="0"
                                            >
                                            @error("tiers.{$tier->id}.{$field}")
                                            <div class="invalid-feedback">{{ $message }}</div>
                                            @enderror
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                            </tbody>
                        </table>

                        <div class="d-flex justify-content-between align-items-center mt-4">
                            <button type="submit" class="btn btn-success">
                                <i class="bi bi-save me-1"></i> Save All Tiers
                            </button>
                        </div>
                    </form>

                    {{-- Tier Deletion --}}
                    <div class="d-flex justify-content-end align-items-center mt-2">
                        <form method="POST" action="{{ route('admin.mmr.destroy') }}" class="d-flex align-items-center gap-2"
                              onsubmit="return confirm('Are you sure you want to delete this tier?')">
                            @csrf
                            @method('DELETE')
                            <select name="tier_id" class="form-select form-select-sm w-auto" required>
                                <option disabled selected value="">Delete a Tier</option>
                                @foreach($tiers as $tier)
                                    @if($tier->city_count !== 0)
                                        <option value="{{ $tier->id }}">City Count: {{ $tier->city_count }}</option>
                                    @endif
                                @endforeach
                            </select>
                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                <i class="bi bi-trash me-1"></i> Delete
                            </button>
                        </form>
                    </div>

                    <small class="text-muted d-block mt-2">Tier 0 is the default fallback and cannot be deleted.</small>
                </div>
            </div>

            {{-- Section: Resource Table --}}
            <div class="mb-3">
                <h4 class="fw-semibold">Member Resource Totals</h4>
                <p class="text-muted mb-2">Resources include both on-hand and banked values at the time of the last sign-in. Red cells indicate members below their required minimums.</p>
            </div>

            <div class="card mb-4">
                <div class="card-body table-responsive">
                    <table class="table table-hover table-striped align-middle mmr-table" id="mmrResourceTable">
                        <thead class="table-light sticky-top">
                        <tr>
                            <th>Nation</th>
                            <th>City Count</th>
                            @foreach($resourceFields as $resource)
                                <th>{{ ucfirst($resource) }}</th>
                            @endforeach
                            <th>MMR Score</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($nations as $nation)
                            @php
                                $eval = $evaluations[$nation->id] ?? null;
                                $tier = app(\App\Services\MMRService::class)->getTierForNation($nation);
                                $signIn = $nation->latestSignIn;
                            @endphp
                            <tr>
                                <td>
                                    <a href="https://politicsandwar.com/nation/id={{ $nation->id }}" target="_blank">
                                        {{ $nation->leader_name }}
                                    </a>
                                </td>
                                <td>{{ $nation->num_cities }}</td>
                                @foreach($resourceFields as $resource)
                                    @php
                                        $have = $signIn->$resource;
                                        $required = $tier->$resource;
                                        $meets = $have >= $required;
                                    @endphp
                                    <td @class([
                                        'bg-danger-subtle text-danger-emphasis' => !$meets,
                                        'text-muted' => $required === 0
                                    ])>
                                        <span data-bs-toggle="tooltip" title="Required: {{ number_format($required) }}">
                                            {{ number_format($have) }}
                                        </span>
                                    </td>
                                @endforeach
                                <td>
                                    @if($eval)
                                        <span class="fw-semibold">{{ $eval['mmr_score'] }}%</span>
                                    @else
                                        <span class="text-muted">N/A</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Section: Military Table --}}
            <div class="mb-3">
                <h4 class="fw-semibold">Member Military Units</h4>
                <p class="text-muted mb-2">Military minimums are derived from the member’s city count and tier requirements. Red cells indicate under-preparedness.</p>
            </div>

            <div class="card">
                <div class="card-body table-responsive">
                    <table class="table table-hover table-striped align-middle mmr-table" id="mmrMilitaryTable">
                        <thead class="table-light sticky-top">
                        <tr>
                            <th>Nation</th>
                            <th>City Count</th>
                            @foreach($unitFields as $unit)
                                <th>{{ ucfirst($unit) }}</th>
                            @endforeach
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($nations as $nation)
                            @php
                                $signIn = $nation->latestSignIn;
                                $tier = app(\App\Services\MMRService::class)->getTierForNation($nation);
                                $required = [
                                    'soldiers' => $tier->barracks * 3000 * $nation->num_cities,
                                    'tanks' => $tier->factories * 250 * $nation->num_cities,
                                    'aircraft' => $tier->hangars * 15 * $nation->num_cities,
                                    'ships' => $tier->drydocks * 5 * $nation->num_cities,
                                    'missiles' => $tier->missiles * $nation->num_cities,
                                    'nukes' => $tier->nukes,
                                    'spies' => $tier->spies,
                                ];
                            @endphp
                            <tr>
                                <td>
                                    <a href="https://politicsandwar.com/nation/id={{ $nation->id }}" target="_blank">
                                        {{ $nation->leader_name }}
                                    </a>
                                </td>
                                <td>{{ $nation->num_cities }}</td>
                                @foreach($unitFields as $unit)
                                    @php
                                        $have = $signIn->$unit;
                                        $min = $required[$unit];
                                        $meets = $have >= $min;
                                    @endphp
                                    <td @class([
                                        'bg-danger-subtle text-danger-emphasis' => !$meets,
                                        'text-muted' => $min === 0
                                    ])>
                                        <span data-bs-toggle="tooltip" title="Required: {{ number_format($min) }}">
                                            {{ number_format($have) }}
                                        </span>
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Back to Top --}}
            <div class="text-end mt-4">
                <a href="#" class="btn btn-light btn-sm">
                    <i class="bi bi-arrow-up"></i> Back to Top
                </a>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        // Bootstrap tooltips
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
            new bootstrap.Tooltip(el);
        });

        // DataTables with default of 50 entries
        new DataTable('#mmrResourceTable', {
            pageLength: 50,
            responsive: true
        });

        new DataTable('#mmrMilitaryTable', {
            pageLength: 50,
            responsive: true
        });
    </script>
@endpush