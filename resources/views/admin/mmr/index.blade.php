@extends('layouts.admin')

@section('content')
    @php
        $mmrService = app(\App\Services\MMRService::class);
        $resourceFields = $mmrService->getResourceFields();
        $readinessFields = ['barracks', 'factories', 'hangars', 'drydocks', 'missiles', 'nukes', 'spies'];
        $unitFields = ['soldiers', 'tanks', 'aircraft', 'ships', 'missiles', 'nukes', 'spies'];
        $weightTotal = array_sum($weights ?? []);
    @endphp

    <x-header title="Minimum Military Requirements" separator>
        <x-slot:subtitle>Manage tier definitions, weighting, assistant settings, and member readiness in one place.</x-slot:subtitle>
    </x-header>

    <div class="space-y-6">
        <div>
            <h2 class="text-lg font-semibold">Defined MMR Tiers</h2>
            <p class="text-sm text-base-content/60">
                Each city-count tier defines per-city minimum resource and unit expectations. Tier 0 is the fallback and cannot be deleted.
            </p>
        </div>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,28rem)_minmax(0,1fr)]">
            <x-card title="Create a New Tier">
                <p class="mb-4 text-sm text-base-content/60">Add the city count first, then use the configuration section below to set per-city minimums.</p>

                <form method="POST" action="{{ route('admin.mmr.store') }}" class="space-y-4">
                    @csrf

                    <label class="block space-y-2">
                        <span class="text-sm font-medium">City count</span>
                        <input type="number" id="city_count" name="city_count" class="input input-bordered w-full" placeholder="e.g. 15" min="1" required>
                    </label>

                    <div class="flex flex-wrap gap-2">
                        <button type="submit" class="btn btn-primary btn-sm">
                            <x-icon name="o-plus-circle" class="size-4" />
                            Add Tier
                        </button>
                        <a href="#tier-config-table" class="btn btn-outline btn-sm">Jump to configuration</a>
                    </div>

                    <div class="rounded-box border border-base-300 bg-base-200/50 p-3 text-sm text-base-content/60">
                        Use the grouped forms below to enter expected resources and readiness for each tier.
                    </div>
                </form>
            </x-card>

            <x-card title="Tier Housekeeping">
                <p class="mb-4 text-sm text-base-content/60">Remove tiers you no longer use. Tier 0 is protected.</p>

                <form method="POST" action="{{ route('admin.mmr.destroy') }}" class="space-y-4" onsubmit="return confirm('Are you sure you want to delete this tier?')">
                    @csrf
                    @method('DELETE')

                    <label class="block space-y-2">
                        <span class="text-sm font-medium">Select tier to delete</span>
                        <select name="tier_id" id="tier_id" class="select select-bordered w-full" required>
                            <option disabled selected value="">Choose a city count</option>
                            @foreach($tiers as $tier)
                                @if($tier->city_count !== 0)
                                    <option value="{{ $tier->id }}">City Count: {{ $tier->city_count }}</option>
                                @endif
                            @endforeach
                        </select>
                    </label>

                    <div>
                        <button type="submit" class="btn btn-outline btn-error btn-sm">
                            <x-icon name="o-trash" class="size-4" />
                            Delete Tier
                        </button>
                    </div>
                </form>

                <div class="mt-4 rounded-box border border-base-300 bg-base-200/50 p-3 text-sm text-base-content/60">
                    Tier 0 is the default fallback and cannot be deleted.
                </div>
            </x-card>
        </div>

        <x-card title="Tier Configuration" id="tier-config-table">
            <x-slot:menu>
                <span class="badge badge-info">Values are per city</span>
            </x-slot:menu>

            <form method="POST" action="{{ route('admin.mmr.updateAll') }}" class="space-y-4">
                @csrf

                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="text-sm text-base-content/60">Numbers are minimum expectations per tier. Empty values default to 0.</div>
                    <button type="submit" class="btn btn-success btn-sm">
                        <x-icon name="o-check" class="size-4" />
                        Save all tiers
                    </button>
                </div>

                <div class="space-y-4">
                    @foreach($tiers as $tier)
                        <div class="collapse collapse-arrow border border-base-300 bg-base-100">
                            <input type="checkbox" @checked($loop->first)>
                            <div class="collapse-title">
                                <div class="flex flex-wrap items-center gap-3">
                                    <span class="badge badge-primary">{{ $tier->city_count }}</span>
                                    <div>
                                        <div class="font-semibold">City count {{ $tier->city_count }}</div>
                                        <div class="text-sm text-base-content/60">Configure per-city resource minimums and readiness.</div>
                                    </div>
                                </div>
                            </div>
                            <div class="collapse-content space-y-6">
                                <div>
                                    <div class="mb-3 flex flex-wrap items-center gap-2">
                                        <span class="font-semibold">Resource minimums</span>
                                        <span class="badge badge-ghost">Per city</span>
                                    </div>
                                    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                                        @foreach($resourceFields as $field)
                                            <label class="block space-y-2">
                                                <span class="text-sm font-medium text-capitalize">{{ $field }}</span>
                                                <input
                                                    type="number"
                                                    name="tiers[{{ $tier->id }}][{{ $field }}]"
                                                    value="{{ old("tiers.{$tier->id}.{$field}", $tier->$field) }}"
                                                    class="input input-bordered w-full @error("tiers.{$tier->id}.{$field}") input-error @enderror"
                                                    min="0"
                                                    inputmode="numeric"
                                                    placeholder="{{ ucfirst($field) }}"
                                                >
                                                @error("tiers.{$tier->id}.{$field}")
                                                    <span class="text-xs text-error">{{ $message }}</span>
                                                @enderror
                                            </label>
                                        @endforeach
                                    </div>
                                </div>

                                <div>
                                    <div class="mb-3 flex flex-wrap items-center gap-2">
                                        <span class="font-semibold">Readiness per city</span>
                                        <span class="badge badge-ghost">Buildings and slots</span>
                                    </div>
                                    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                                        @foreach($readinessFields as $field)
                                            <label class="block space-y-2">
                                                <span class="text-sm font-medium text-capitalize">{{ $field }}</span>
                                                <input
                                                    type="number"
                                                    name="tiers[{{ $tier->id }}][{{ $field }}]"
                                                    value="{{ old("tiers.{$tier->id}.{$field}", $tier->$field) }}"
                                                    class="input input-bordered w-full @error("tiers.{$tier->id}.{$field}") input-error @enderror"
                                                    min="0"
                                                    inputmode="numeric"
                                                    placeholder="{{ ucfirst($field) }}"
                                                >
                                                @error("tiers.{$tier->id}.{$field}")
                                                    <span class="text-xs text-error">{{ $message }}</span>
                                                @enderror
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="text-sm text-base-content/60">Changes save everything at once, so review the tiers before submitting.</div>
                    <button type="submit" class="btn btn-success">
                        <x-icon name="o-check" class="size-4" />
                        Save all tiers
                    </button>
                </div>
            </form>
        </x-card>

        <x-card title="Bulk Edit Tier Resources">
            <x-slot:menu>
                <span class="badge badge-primary">Per city updates</span>
            </x-slot:menu>

            <form method="POST" action="{{ route('admin.mmr.bulk-edit-resources') }}" class="space-y-6">
                @csrf

                <div class="grid gap-6 xl:grid-cols-[minmax(0,20rem)_minmax(0,1fr)]">
                    <div class="space-y-4">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="font-semibold">Select tiers</span>
                            <div class="flex gap-2">
                                <button type="button" class="btn btn-outline btn-sm" id="mmrBulkSelectAll">Select all</button>
                                <button type="button" class="btn btn-outline btn-sm" id="mmrBulkClearAll">Clear</button>
                            </div>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            @foreach($tiers as $tier)
                                <label class="label cursor-pointer justify-start gap-2 rounded-box border border-base-300 px-3 py-2">
                                    <input class="checkbox checkbox-sm mmr-bulk-tier" type="checkbox" name="tier_ids[]" value="{{ $tier->id }}" @checked(in_array($tier->id, old('tier_ids', [])))>
                                    <span class="text-sm">City count {{ $tier->city_count }}</span>
                                </label>
                            @endforeach
                        </div>

                        @error('tier_ids')
                            <div class="text-sm text-error">{{ $message }}</div>
                        @enderror
                        @error('tier_ids.*')
                            <div class="text-sm text-error">{{ $message }}</div>
                        @enderror

                        <div class="text-sm text-base-content/60">Choose one or more city-count tiers to update together.</div>
                    </div>

                    <div class="space-y-6">
                        <div>
                            <div class="mb-3 flex flex-wrap items-center gap-2">
                                <span class="font-semibold">Resources</span>
                                <span class="badge badge-ghost">Per city</span>
                            </div>
                            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                                @foreach($resourceFields as $field)
                                    <label class="block space-y-2">
                                        <span class="text-sm font-medium text-capitalize">{{ $field }}</span>
                                        <input type="number" name="resources[{{ $field }}]" value="{{ old("resources.{$field}") }}" class="input input-bordered w-full @error("resources.{$field}") input-error @enderror" min="0" inputmode="numeric" placeholder="Leave blank">
                                        @error("resources.{$field}")
                                            <span class="text-xs text-error">{{ $message }}</span>
                                        @enderror
                                    </label>
                                @endforeach
                            </div>
                        </div>

                        <div>
                            <div class="mb-3 flex flex-wrap items-center gap-2">
                                <span class="font-semibold">Readiness</span>
                                <span class="badge badge-ghost">Buildings and slots</span>
                            </div>
                            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                                @foreach($readinessFields as $field)
                                    <label class="block space-y-2">
                                        <span class="text-sm font-medium text-capitalize">{{ $field }}</span>
                                        <input type="number" name="resources[{{ $field }}]" value="{{ old("resources.{$field}") }}" class="input input-bordered w-full @error("resources.{$field}") input-error @enderror" min="0" inputmode="numeric" placeholder="Leave blank">
                                        @error("resources.{$field}")
                                            <span class="text-xs text-error">{{ $message }}</span>
                                        @enderror
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>

                @error('resources')
                    <div class="text-sm text-error">{{ $message }}</div>
                @enderror

                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="text-sm text-base-content/60">Filled fields replace per-city values on the selected tiers.</div>
                    <button type="submit" class="btn btn-primary">
                        <x-icon name="o-check-circle" class="size-4" />
                        Apply Bulk Edit
                    </button>
                </div>
            </form>
        </x-card>

        <x-card title="Resource Weighting">
            <x-slot:menu>
                <span class="badge badge-ghost">Current total: {{ number_format($weightTotal, 2) }}%</span>
            </x-slot:menu>

            <form method="POST" action="{{ route('admin.mmr.weights.update') }}" class="space-y-4">
                @csrf

                @error('weights')
                    <div class="alert alert-warning"><span>{{ $message }}</span></div>
                @enderror

                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                    @foreach($resourceFields as $resource)
                        <label class="block space-y-2">
                            <span class="flex justify-between text-sm font-medium text-capitalize">
                                <span>{{ $resource }}</span>
                                <span class="text-base-content/60">{{ number_format($weights[$resource] ?? 0, 2) }}%</span>
                            </span>
                            <div class="join w-full">
                                <input
                                    type="number"
                                    name="weights[{{ $resource }}]"
                                    class="input input-bordered join-item w-full @error("weights.{$resource}") input-error @enderror mmr-weight-input"
                                    step="0.01"
                                    min="0"
                                    value="{{ old("weights.{$resource}", $weights[$resource] ?? 0) }}"
                                >
                                <span class="join-item flex items-center border border-base-300 bg-base-200 px-3 text-sm">%</span>
                            </div>
                            @error("weights.{$resource}")
                                <span class="text-xs text-error">{{ $message }}</span>
                            @enderror
                        </label>
                    @endforeach
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="text-sm text-base-content/60">Adjust weights to emphasize specific resources. The live total updates as you type.</div>
                    <div class="flex flex-wrap items-center gap-3">
                        <span class="font-semibold" id="mmrWeightTotal">Total: {{ number_format($weightTotal, 2) }}%</span>
                        <button type="submit" class="btn btn-primary btn-sm">
                            <x-icon name="o-adjustments-horizontal" class="size-4" />
                            Save Weights
                        </button>
                    </div>
                </div>
            </form>
        </x-card>

        <x-card title="MMR Assistant Resource Settings">
            <p class="mb-4 text-sm text-base-content/60">
                Enable or disable specific resources and adjust surcharge values. These affect how resources are priced and whether they are purchasable via MMR Assistant.
            </p>

            <form method="POST" action="{{ route('admin.mmr.assistant.update') }}" class="space-y-4">
                @csrf

                <label class="label cursor-pointer justify-start gap-3">
                    <input class="toggle toggle-primary" type="checkbox" id="mmrEnabledToggle" name="enabled" value="1" @checked(\App\Services\SettingService::getMMRAssistantEnabled())>
                    <span class="label-text">
                        Enable MMR Assistant Globally
                        <span class="badge ml-2 {{ \App\Services\SettingService::getMMRAssistantEnabled() ? 'badge-success' : 'badge-ghost' }}">
                            {{ \App\Services\SettingService::getMMRAssistantEnabled() ? 'Enabled' : 'Disabled' }}
                        </span>
                    </span>
                </label>

                <div class="overflow-x-auto rounded-box border border-base-300">
                    <table class="table table-zebra">
                        <thead>
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
                                    <input type="checkbox" class="checkbox checkbox-sm" name="resources[{{ $setting->resource }}][enabled]" value="1" @checked($setting->enabled)>
                                </td>
                                <td>
                                    <input type="number" name="resources[{{ $setting->resource }}][surcharge_pct]" class="input input-bordered input-sm w-full max-w-32" step="0.01" min="0" value="{{ $setting->surcharge_pct }}">
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3">
                    <button type="submit" class="btn btn-success">
                        <x-icon name="o-check" class="size-4" />
                        Save Settings
                    </button>

                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-sm text-base-content/60">Set all surcharges to</span>
                        <input type="number" id="setAllSurcharge" class="input input-bordered input-sm w-28" step="0.01" min="0">
                        <button type="button" class="btn btn-outline btn-sm" id="applySurchargeToAll">Apply</button>
                    </div>
                </div>
            </form>
        </x-card>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-lg font-semibold">Member Resource Totals</h2>
                <p class="text-sm text-base-content/60">
                    Requirements scale by city count. Resources include on-hand and banked values at the last sign-in; red cells indicate members below the required minimums.
                </p>
            </div>
            <button type="button" class="btn btn-outline btn-sm" id="mmrExportCsv">
                <x-icon name="o-arrow-down-tray" class="size-4" />
                Export Resources + Units CSV
            </button>
        </div>

        <x-card>
            <div class="overflow-x-auto rounded-box border border-base-300">
                <table class="table table-zebra mmr-table" id="mmrResourceTable">
                    <thead>
                    <tr>
                        <th>Leader</th>
                        <th>Discord</th>
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
                                <a href="https://politicsandwar.com/nation/id={{ $nation->id }}" target="_blank" rel="noopener" class="link link-hover">
                                    {{ $nation->leader_name }}
                                </a>
                            </td>
                            <td>{{ $nation->discord ?: '—' }}</td>
                            <td>{{ $nation->num_cities }}</td>
                            @foreach($resourceFields as $resource)
                                @php
                                    $have = $signIn->$resource;
                                    $required = $tier->$resource * $nation->num_cities;
                                    $meets = $have >= $required;
                                @endphp
                                <td class="{{ ! $meets ? 'bg-error/10 text-error' : ($required === 0 ? 'text-base-content/50' : '') }}" title="Required: {{ number_format($required) }} ({{ number_format($tier->$resource) }} per city)">
                                    {{ number_format($have) }}
                                </td>
                            @endforeach
                            <td>
                                @if($eval)
                                    @php
                                        $score = $eval['mmr_score'] ?? 0;
                                        $resourceMet = $eval['meets_resource_requirements'] ?? false;
                                        $unitMet = $eval['meets_unit_requirements'] ?? false;
                                    @endphp
                                    <div class="space-y-2">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="font-semibold">{{ $score }}%</span>
                                            <span class="badge {{ $resourceMet ? 'badge-success' : 'badge-warning' }}">Resources {{ $resourceMet ? 'OK' : 'Low' }}</span>
                                            <span class="badge {{ $unitMet ? 'badge-success' : 'badge-warning' }}">Units {{ $unitMet ? 'OK' : 'Low' }}</span>
                                        </div>
                                        <progress class="progress {{ $score >= 90 ? 'progress-success' : ($score >= 70 ? 'progress-warning' : 'progress-error') }} h-2 w-full" value="{{ min($score, 100) }}" max="100"></progress>
                                    </div>
                                @else
                                    <span class="text-base-content/60">N/A</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>

        <div>
            <h2 class="text-lg font-semibold">Member Military Units</h2>
            <p class="text-sm text-base-content/60">Military minimums are derived from each member’s city count and tier requirements. Red cells indicate under-preparedness.</p>
        </div>

        <x-card>
            <div class="overflow-x-auto rounded-box border border-base-300">
                <table class="table table-zebra mmr-table" id="mmrMilitaryTable">
                    <thead>
                    <tr>
                        <th>Leader</th>
                        <th>Discord</th>
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
                                <a href="https://politicsandwar.com/nation/id={{ $nation->id }}" target="_blank" rel="noopener" class="link link-hover">
                                    {{ $nation->leader_name }}
                                </a>
                            </td>
                            <td>{{ $nation->discord ?: '—' }}</td>
                            <td>{{ $nation->num_cities }}</td>
                            @foreach($unitFields as $unit)
                                @php
                                    $have = $signIn->$unit;
                                    $min = $required[$unit];
                                    $meets = $have >= $min;
                                @endphp
                                <td class="{{ ! $meets ? 'bg-error/10 text-error' : ($min === 0 ? 'text-base-content/50' : '') }}" title="Required: {{ number_format($min) }}">
                                    {{ number_format($have) }}
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>

        <div class="text-right">
            <a href="#" class="btn btn-outline btn-sm">Back to Top</a>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('codex:page-ready', () => {
            const weightInputs = document.querySelectorAll('.mmr-weight-input');
            const weightTotal = document.getElementById('mmrWeightTotal');

            const updateWeightTotal = () => {
                const total = Array.from(weightInputs).reduce((sum, input) => {
                    const value = parseFloat(input.value);

                    return sum + (Number.isNaN(value) ? 0 : value);
                }, 0);

                if (weightTotal) {
                    weightTotal.textContent = `Total: ${total.toFixed(2)}%`;
                    weightTotal.classList.toggle('text-error', Math.abs(total - 100) > 0.01);
                }
            };

            weightInputs.forEach((input) => input.addEventListener('input', updateWeightTotal));
            updateWeightTotal();

            const surchargeApplyButton = document.getElementById('applySurchargeToAll');
            const surchargeInput = document.getElementById('setAllSurcharge');

            surchargeApplyButton?.addEventListener('click', () => {
                const value = parseFloat(surchargeInput?.value ?? '');
                if (Number.isNaN(value)) {
                    return;
                }

                document.querySelectorAll('input[name$="[surcharge_pct]"]').forEach((input) => {
                    input.value = value.toFixed(2);
                });
            });

            const bulkTierCheckboxes = document.querySelectorAll('.mmr-bulk-tier');
            document.getElementById('mmrBulkSelectAll')?.addEventListener('click', () => {
                bulkTierCheckboxes.forEach((input) => {
                    input.checked = true;
                });
            });

            document.getElementById('mmrBulkClearAll')?.addEventListener('click', () => {
                bulkTierCheckboxes.forEach((input) => {
                    input.checked = false;
                });
            });

            const stripHtml = (value) => {
                const div = document.createElement('div');
                div.innerHTML = String(value ?? '');

                return div.textContent.trim();
            };

            const formatCsvLine = (cells) => {
                return cells.map((cell) => `"${String(cell ?? '').replace(/"/g, '""')}"`).join(',');
            };

            const getTableSection = (tableId) => {
                const table = document.getElementById(tableId);
                if (! table) {
                    return { headers: [], rows: [] };
                }

                const headers = Array.from(table.querySelectorAll('thead th')).map((th) => stripHtml(th.innerHTML));
                const rows = Array.from(table.querySelectorAll('tbody tr')).map((row) => {
                    return Array.from(row.children).map((cell) => stripHtml(cell.innerHTML));
                });

                return { headers, rows };
            };

            document.getElementById('mmrExportCsv')?.addEventListener('click', () => {
                const resourceSection = getTableSection('mmrResourceTable');
                const unitSection = getTableSection('mmrMilitaryTable');

                const combinedHeaders = [...resourceSection.headers, ...unitSection.headers.slice(2)];
                const unitRowMap = new Map();

                unitSection.rows.forEach((row) => {
                    unitRowMap.set(`${row[0]}::${row[1]}`, row);
                });

                const combinedRows = resourceSection.rows.map((resourceRow) => {
                    const unitRow = unitRowMap.get(`${resourceRow[0]}::${resourceRow[1]}`) ?? [];

                    return [...resourceRow, ...unitRow.slice(2)];
                });

                const csv = [combinedHeaders, ...combinedRows].map(formatCsvLine).join('\n');
                const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
                const url = URL.createObjectURL(blob);
                const link = document.createElement('a');

                link.href = url;
                link.download = 'mmr-resources-units.csv';
                link.click();

                URL.revokeObjectURL(url);
            });
        });
    </script>
@endpush
