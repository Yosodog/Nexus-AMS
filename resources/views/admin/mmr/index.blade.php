@extends('layouts.admin')

@section('content')
    <div class="app-content-header mb-3">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center">
                <h3 class="mb-0">Minimum Military Requirements</h3>
            </div>
        </div>
    </div>

    <div class="app-content">
        <div class="container-fluid">
            @php
                $mmrService = app(\App\Services\MMRService::class);
                $resourceFields = $mmrService->getResourceFields();
                $readinessFields = ['barracks', 'factories', 'hangars', 'drydocks', 'missiles', 'nukes', 'spies'];
                $unitFields = ['soldiers', 'tanks', 'aircraft', 'ships', 'missiles', 'nukes', 'spies'];
                $weightTotal = array_sum($weights ?? []);
            @endphp

            {{-- Section: Tier Definitions --}}
            <div class="mb-4">
                <h4 class="fw-semibold">Defined MMR Tiers</h4>
                <p class="text-muted mb-2">Each city count tier defines the per-city minimum resource and unit expectations for members. Tier 0 is the fallback and cannot be deleted.</p>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-lg-7">
                    <div class="card h-100">
                        <div class="card-header">
                            <div class="d-flex align-items-center justify-content-between">
                                <h5 class="card-title mb-0">Create a New Tier</h5>
                            </div>
                        </div>
                        <div class="card-body">
                            <p class="text-muted">Add the city count you need, then set resource and readiness minimums in the table.</p>

                            <form method="POST" action="{{ route('admin.mmr.store') }}" class="row g-3 align-items-end">
                                @csrf
                                <div class="col-sm-6 col-md-5">
                                    <label for="city_count" class="form-label mb-1">City count</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-buildings"></i></span>
                                        <input type="number"
                                               id="city_count"
                                               name="city_count"
                                               class="form-control"
                                               placeholder="e.g. 15"
                                               min="1"
                                               required>
                                    </div>
                                </div>
                                <div class="col-sm-6 col-md-4 d-flex gap-2">
                                    <button type="submit" class="btn btn-sm btn-primary flex-grow-1">
                                        <i class="bi bi-plus-circle me-1"></i> Add Tier
                                    </button>
                                    <a href="#tier-config-table" class="btn btn-sm btn-outline-secondary" title="Jump to configuration">
                                        <i class="bi bi-arrow-down-short"></i>
                                    </a>
                                </div>
                                <div class="col-12">
                                    <div class="d-flex align-items-center gap-2 text-muted small">
                                        <i class="bi bi-lightbulb-fill text-warning"></i>
                                        <span>Use the grouped table below to enter expected resources and readiness for each tier.</span>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="card h-100">
                        <div class="card-header">
                            <div class="d-flex align-items-center justify-content-between">
                                <h5 class="card-title mb-0">Tier housekeeping</h5>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <div>
                                    <p class="text-muted mb-2">Remove tiers you no longer use. Tier 0 is protected.</p>
                                </div>
                            </div>

                            {{-- Tier Deletion --}}
                            <form method="POST"
                                  action="{{ route('admin.mmr.destroy') }}"
                                  class="row g-2 align-items-end"
                                  onsubmit="return confirm('Are you sure you want to delete this tier?')">
                                @csrf
                                @method('DELETE')
                                <div class="col-12">
                                    <label class="form-label mb-1" for="tier_id">Select tier to delete</label>
                                    <select name="tier_id" id="tier_id" class="form-select form-select-sm" required>
                                        <option disabled selected value="">Choose a city count</option>
                                        @foreach($tiers as $tier)
                                            @if($tier->city_count !== 0)
                                                <option value="{{ $tier->id }}">City Count: {{ $tier->city_count }}</option>
                                            @endif
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-12 d-flex justify-content-end">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                        <i class="bi bi-trash me-1"></i> Delete Tier
                                    </button>
                                </div>
                            </form>

                            <div class="mt-3 p-2 bg-body-tertiary rounded">
                                <div class="d-flex align-items-start gap-2 text-muted small">
                                    <i class="bi bi-info-circle"></i>
                                    <span>Tier 0 is the default fallback and cannot be deleted.</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-5">
                <div class="card-header">
                    <div class="d-flex align-items-center justify-content-between">
                        <h5 class="card-title mb-0">Tier Configuration</h5>
                        <span class="badge bg-info-subtle text-info-emphasis">Values are per city</span>
                    </div>
                </div>
                <div class="card-body table-responsive" id="tier-config-table">
                    <form method="POST" action="{{ route('admin.mmr.updateAll') }}">
                        @csrf
                        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                            <div class="d-flex align-items-center gap-2 text-muted small">
                                <i class="bi bi-grid-3x3-gap"></i>
                                <span>Numbers are minimum expectations per tier. Empty values default to 0.</span>
                            </div>
                            <button type="submit" class="btn btn-success btn-sm">
                                <i class="bi bi-save me-1"></i> Save all tiers
                            </button>
                        </div>

                        <div class="accordion" id="tierAccordion">
                            @foreach($tiers as $tier)
                                <div class="accordion-item mb-3 border-0 shadow-sm">
                                    <h2 class="accordion-header" id="heading{{ $tier->id }}">
                                        <button class="accordion-button {{ !$loop->first ? 'collapsed' : '' }}" type="button"
                                                data-bs-toggle="collapse"
                                                data-bs-target="#collapse{{ $tier->id }}"
                                                aria-expanded="{{ $loop->first ? 'true' : 'false' }}"
                                                aria-controls="collapse{{ $tier->id }}">
                                            <div class="d-flex align-items-center gap-3">
                                                <span class="badge bg-primary-subtle text-primary-emphasis fs-6">{{ $tier->city_count }}</span>
                                                <div class="d-flex flex-column">
                                                    <span class="fw-semibold">City count {{ $tier->city_count }}</span>
                                                    <small class="text-muted">Configure per-city resource minimums and readiness</small>
                                                </div>
                                            </div>
                                        </button>
                                    </h2>
                                    <div id="collapse{{ $tier->id }}" class="accordion-collapse collapse {{ $loop->first ? 'show' : '' }}"
                                         aria-labelledby="heading{{ $tier->id }}" data-bs-parent="#tierAccordion">
                                        <div class="accordion-body">
                                            <div class="row g-4">
                                                <div class="col-12">
                                                    <div class="d-flex align-items-center gap-2 mb-2">
                                                        <span class="fw-semibold">Resource minimums</span>
                                                        <span class="badge bg-light text-dark">Per city</span>
                                                    </div>
                                                    <div class="row g-3">
                                                        @foreach($resourceFields as $field)
                                                            <div class="col-12 col-md-6 col-lg-4 col-xl-3">
                                                                <label class="form-label text-capitalize">{{ $field }}</label>
                                                                <input type="number"
                                                                       name="tiers[{{ $tier->id }}][{{ $field }}]"
                                                                       value="{{ old("tiers.{$tier->id}.{$field}", $tier->$field) }}"
                                                                       class="form-control @error("tiers.{$tier->id}.{$field}") is-invalid @enderror text-end"
                                                                       min="0"
                                                                       inputmode="numeric"
                                                                       placeholder="{{ ucfirst($field) }}">
                                                                @error("tiers.{$tier->id}.{$field}")
                                                                <div class="invalid-feedback">{{ $message }}</div>
                                                                @enderror
                                                            </div>
                                                        @endforeach
                                                    </div>
                                                </div>

                                                <div class="col-12">
                                                    <hr class="my-3">
                                                    <div class="d-flex align-items-center gap-2 mb-2">
                                                        <span class="fw-semibold">Readiness per city</span>
                                                        <span class="badge bg-light text-dark">Buildings &amp; slots</span>
                                                    </div>
                                                    <div class="row g-3">
                                                        @foreach($readinessFields as $field)
                                                            <div class="col-12 col-md-6 col-lg-4 col-xl-3">
                                                                <label class="form-label text-capitalize">{{ $field }}</label>
                                                                <input type="number"
                                                                       name="tiers[{{ $tier->id }}][{{ $field }}]"
                                                                       value="{{ old("tiers.{$tier->id}.{$field}", $tier->$field) }}"
                                                                       class="form-control @error("tiers.{$tier->id}.{$field}") is-invalid @enderror text-end"
                                                                       min="0"
                                                                       inputmode="numeric"
                                                                       placeholder="{{ ucfirst($field) }}">
                                                                @error("tiers.{$tier->id}.{$field}")
                                                                <div class="invalid-feedback">{{ $message }}</div>
                                                                @enderror
                                                            </div>
                                                        @endforeach
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <div class="d-flex flex-wrap justify-content-between align-items-center mt-3 gap-2">
                            <div class="d-flex align-items-center gap-2 text-muted small">
                                <i class="bi bi-shield-check text-success"></i>
                                <span>Changes save everything at once—review before submitting.</span>
                            </div>
                            <button type="submit" class="btn btn-success">
                                <i class="bi bi-save me-1"></i> Save all tiers
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            {{-- Section: Resource Weighting --}}
            <div class="card mb-5">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="card-title mb-0">Resource Weighting</h5>
                        <small class="text-muted">Weights must total 100% and control how the MMR score is calculated.</small>
                    </div>
                    <span class="badge bg-light text-dark">Current total: {{ number_format($weightTotal, 2) }}%</span>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.mmr.weights.update') }}">
                        @csrf
                        @error('weights')
                        <div class="alert alert-warning" role="alert">
                            {{ $message }}
                        </div>
                        @enderror

                        <div class="row g-3">
                            @foreach($resourceFields as $resource)
                                <div class="col-6 col-md-4 col-lg-3 col-xl-2">
                                    <label class="form-label text-capitalize d-flex justify-content-between align-items-center">
                                        <span>{{ $resource }}</span>
                                        <span class="text-muted small">{{ number_format($weights[$resource] ?? 0, 2) }}%</span>
                                    </label>
                                    <div class="input-group">
                                        <input type="number"
                                               name="weights[{{ $resource }}]"
                                               class="form-control @error("weights.{$resource}") is-invalid @enderror mmr-weight-input"
                                               step="0.01"
                                               min="0"
                                               value="{{ old("weights.{$resource}", $weights[$resource] ?? 0) }}"
                                               aria-label="{{ ucfirst($resource) }} weight" />
                                        <span class="input-group-text">%</span>
                                    </div>
                                    @error("weights.{$resource}")
                                    <div class="invalid-feedback d-block">{{ $message }}</div>
                                    @enderror
                                </div>
                            @endforeach
                        </div>

                        <div class="d-flex justify-content-between align-items-center mt-3">
                            <div class="text-muted small">
                                Adjust weights to emphasize specific resources. The live total updates as you type.
                            </div>
                            <div class="d-flex align-items-center gap-3">
                                <span class="fw-semibold" id="mmrWeightTotal">Total: {{ number_format($weightTotal, 2) }}%</span>
                                <button type="submit" class="btn btn-primary btn-sm">
                                    <i class="bi bi-sliders me-1"></i> Save Weights
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            {{-- Section: MMR Assistant Resource Settings --}}
            <div class="mb-5">
                <h4 class="fw-semibold d-flex align-items-center">
                    MMR Assistant Resource Settings

                    {{-- Popover for global helper --}}
                    <i class="bi bi-info-circle-fill ms-2 text-muted"
                       tabindex="0"
                       data-bs-toggle="popover"
                       data-bs-trigger="focus"
                       data-bs-placement="right"
                       data-bs-html="true"
                       title="What is this?"
                       data-bs-content="
               <strong>MMR Assistant</strong> automatically buys resources for members based on their Direct Deposit after-tax income.
               <br><br>
               You can globally enable or disable this feature. When disabled, no purchases will be made, regardless of user settings.
           "></i>
                </h4>

                <p class="text-muted mb-3">
                    Enable or disable specific resources and adjust surcharge values. These affect how resources are priced and whether they’re purchasable via MMR Assistant.
                </p>

                <div class="card">
                    <div class="card-body">
                        <form method="POST" action="{{ route('admin.mmr.assistant.update') }}">
                            @csrf

                            {{-- Global toggle --}}
                            <div class="form-check form-switch mb-4">
                                <input class="form-check-input"
                                       type="checkbox"
                                       id="mmrEnabledToggle"
                                       name="enabled"
                                       value="1"
                                        @checked(\App\Services\SettingService::getMMRAssistantEnabled()) />
                                <label class="form-check-label fw-semibold" for="mmrEnabledToggle">
                                    Enable MMR Assistant Globally

                                    @if(\App\Services\SettingService::getMMRAssistantEnabled())
                                        <span class="badge bg-success ms-2">Enabled</span>
                                    @else
                                        <span class="badge bg-secondary ms-2">Disabled</span>
                                    @endif
                                </label>
                            </div>

                            <div class="table-responsive mb-3">
                                <table class="table table-bordered align-middle">
                                    <thead class="table-light">
                                    <tr>
                                        <th>Resource</th>
                                        <th>Enabled</th>
                                        <th>Surcharge %</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    @foreach(\App\Models\MMRSetting::orderBy('resource')->get() as $setting)
                                        <tr>
                                            <td class="text-capitalize">{{ $setting->resource }}</td>
                                            <td>
                                                <input type="checkbox"
                                                       name="resources[{{ $setting->resource }}][enabled]"
                                                       value="1"
                                                        @checked($setting->enabled) />
                                            </td>
                                            <td>
                                                <input type="number"
                                                       name="resources[{{ $setting->resource }}][surcharge_pct]"
                                                       class="form-control"
                                                       step="0.01"
                                                       min="0"
                                                       value="{{ $setting->surcharge_pct }}" />
                                            </td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            </div>

                            <div class="d-flex justify-content-between align-items-center">
                                <button type="submit" class="btn btn-success">
                                    <i class="bi bi-save me-1"></i> Save Settings
                                </button>

                                <div class="input-group" style="width: 300px;">
                                    <span class="input-group-text">Set all surcharges to</span>
                                    <input type="number" id="setAllSurcharge" class="form-control" step="0.01" min="0">
                                    <button type="button" class="btn btn-outline-secondary" id="applySurchargeToAll">Apply</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            {{-- Section: Resource Table --}}
            <div class="mb-3">
                <h4 class="fw-semibold">Member Resource Totals</h4>
                <p class="text-muted mb-2">Requirements scale by city count (per-city values multiplied by total cities). Resources include on-hand and banked values at the last sign-in; red cells indicate members below required minimums.</p>
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
                                $tier = $mmrService->getTierForNation($nation);
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
                                        $required = $tier->$resource * $nation->num_cities;
                                        $meets = $have >= $required;
                                    @endphp
                                    <td @class([
                                        'bg-danger-subtle text-danger-emphasis' => !$meets,
                                        'text-muted' => $required === 0
                                    ])>
                                        <span data-bs-toggle="tooltip" title="Required: {{ number_format($required) }} ({{ number_format($tier->$resource) }} per city)">
                                            {{ number_format($have) }}
                                        </span>
                                    </td>
                                @endforeach
                                <td>
                                    @if($eval)
                                        @php
                                            $score = $eval['mmr_score'] ?? 0;
                                            $resourceMet = $eval['meets_resource_requirements'] ?? false;
                                            $unitMet = $eval['meets_unit_requirements'] ?? false;
                                            $barClass = $score >= 90 ? 'bg-success' : ($score >= 70 ? 'bg-warning' : 'bg-danger');
                                        @endphp
                                        <div class="d-flex flex-column gap-1">
                                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                                <span class="fw-semibold">{{ $score }}%</span>
                                                <span class="badge {{ $resourceMet ? 'bg-success-subtle text-success-emphasis' : 'bg-warning-subtle text-warning-emphasis' }}">
                                                    Resources {{ $resourceMet ? 'OK' : 'Low' }}
                                                </span>
                                                <span class="badge {{ $unitMet ? 'bg-success-subtle text-success-emphasis' : 'bg-warning-subtle text-warning-emphasis' }}">
                                                    Units {{ $unitMet ? 'OK' : 'Low' }}
                                                </span>
                                            </div>
                                            <div class="progress" style="height: 6px;">
                                                <div class="progress-bar {{ $barClass }}" role="progressbar" style="width: {{ min($score, 100) }}%" aria-valuenow="{{ $score }}" aria-valuemin="0" aria-valuemax="100"></div>
                                            </div>
                                        </div>
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
                                $tier = $mmrService->getTierForNation($nation);
                                $required = $mmrService->buildUnitRequirements($tier, $nation->num_cities);
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
        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
                new bootstrap.Tooltip(el);
            });

            document.querySelectorAll('[data-bs-toggle="popover"]').forEach(el => {
                new bootstrap.Popover(el);
            });

            new DataTable('#mmrResourceTable', {
                pageLength: 50,
                responsive: true
            });

            new DataTable('#mmrMilitaryTable', {
                pageLength: 50,
                responsive: true
            });

            const weightInputs = document.querySelectorAll('.mmr-weight-input');
            const weightTotal = document.getElementById('mmrWeightTotal');

            const updateWeightTotal = () => {
                const total = Array.from(weightInputs).reduce((sum, input) => {
                    const value = parseFloat(input.value);

                    return sum + (isNaN(value) ? 0 : value);
                }, 0);

                if (weightTotal) {
                    weightTotal.textContent = `Total: ${total.toFixed(2)}%`;
                    weightTotal.classList.toggle('text-danger', Math.abs(total - 100) > 0.01);
                }
            };

            weightInputs.forEach(input => input.addEventListener('input', updateWeightTotal));
            updateWeightTotal();

            const surchargeApplyButton = document.getElementById('applySurchargeToAll');
            const surchargeInput = document.getElementById('setAllSurcharge');

            if (surchargeApplyButton && surchargeInput) {
                surchargeApplyButton.addEventListener('click', () => {
                    const value = parseFloat(surchargeInput.value);
                    if (isNaN(value)) {
                        return;
                    }

                    document.querySelectorAll('input[name$="[surcharge_pct]"]').forEach(input => {
                        input.value = value.toFixed(2);
                    });
                });
            }
        });
    </script>
@endpush
