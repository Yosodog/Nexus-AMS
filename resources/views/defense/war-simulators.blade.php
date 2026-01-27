
@extends('layouts.main')

@section('content')
    <div class="container mx-auto space-y-6" x-data="warSim()" x-init="init()">
        <div class="rounded-2xl bg-base-100 border border-base-300 p-6 shadow">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div class="space-y-2">
                    <p class="text-xs uppercase tracking-[0.3em] text-base-content/60">Defense desk</p>
                    <h1 class="text-3xl font-bold leading-tight">War Simulators</h1>
                    <p class="text-sm text-base-content/70">Run RNG-accurate battle sims and export results for ops.</p>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <div class="form-control">
                        <label class="label">
                            <span class="label-text text-xs uppercase tracking-wide tooltip" data-tip="Iterations = number of simulated battles. Higher = smoother probabilities but slower. Range 100-20000.">Iterations</span>
                        </label>
                        <input type="number" min="100" max="20000" step="100" x-model.number="iterations"
                               @blur="normalizeIterations()"
                               class="input input-bordered w-32"/>
                    </div>
                    <div class="form-control">
                        <label class="label">
                            <span class="label-text text-xs uppercase tracking-wide tooltip" data-tip="Seed makes RNG deterministic. Same inputs + seed = same results. Leave blank for true random.">Seed (optional)</span>
                        </label>
                        <input type="number" x-model.number="seed" class="input input-bordered w-40"/>
                    </div>
                </div>
            </div>
        </div>

        <template x-if="errorMessage">
            <div class="alert alert-error shadow">
                <span x-text="errorMessage"></span>
            </div>
        </template>

        <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
            <div class="rounded-2xl bg-base-100 border border-base-300 p-6 shadow space-y-4">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold">Attacker</h2>
                    <span class="badge badge-outline" x-text="attacker.nation_id ? `#${attacker.nation_id}` : 'Manual'"></span>
                </div>
                <div class="rounded-xl bg-base-200/60 p-3 flex items-center gap-3">
                    <div class="avatar">
                        <div class="w-12 rounded-full ring ring-primary/30 ring-offset-base-100 ring-offset-2">
                            <img :src="attacker.flag || 'https://placehold.co/80x80?text=?'" alt="Attacker flag"/>
                        </div>
                    </div>
                    <div class="flex-1">
                        <div class="font-semibold" x-text="attacker.nation_name || 'Unknown nation'"></div>
                        <div class="text-xs text-base-content/60">
                            Leader: <span x-text="attacker.leader_name || 'Unknown'"></span>
                        </div>
                        <div class="text-xs text-base-content/50">
                            Updated: <span x-text="formatDate(attacker.last_updated) || 'Unknown'"></span>
                        </div>
                    </div>
                </div>
                <div class="form-control">
                    <label class="label">
                        <span class="label-text text-xs uppercase tracking-wide tooltip" data-tip="Load a different attacker by nation ID. Defaults to your nation.">Fetch Attacker Nation ID</span>
                    </label>
                    <div class="flex gap-2">
                        <input type="number" min="1" x-model.number="lookupAttackerId" class="input input-bordered flex-1"/>
                        <button class="btn btn-outline" @click="loadAttacker()">Fetch</button>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <label class="form-control">
                        <span class="label-text text-xs">Soldiers</span>
                        <input type="number" min="0" x-model.number="attacker.soldiers" class="input input-bordered"/>
                    </label>
                    <label class="form-control">
                        <span class="label-text text-xs">Tanks</span>
                        <input type="number" min="0" x-model.number="attacker.tanks" class="input input-bordered"/>
                    </label>
                    <label class="form-control">
                        <span class="label-text text-xs">Aircraft</span>
                        <input type="number" min="0" x-model.number="attacker.aircraft" class="input input-bordered"/>
                    </label>
                    <label class="form-control">
                        <span class="label-text text-xs">Ships</span>
                        <input type="number" min="0" x-model.number="attacker.ships" class="input input-bordered"/>
                    </label>
                    <label class="form-control">
                        <span class="label-text text-xs">Cities</span>
                        <input type="number" min="0" x-model.number="attacker.cities" class="input input-bordered"/>
                    </label>
                    <label class="form-control">
                        <span class="label-text text-xs">Highest Infra</span>
                        <input type="number" min="0" step="0.01" x-model.number="attacker.highest_city_infra" class="input input-bordered"/>
                    </label>
                    <label class="form-control">
                        <span class="label-text text-xs tooltip" data-tip="Population from the highest-infra city. Used for resistance bonus.">Highest City Pop</span>
                        <input type="number" min="0" x-model.number="attacker.highest_city_population" class="input input-bordered"/>
                    </label>
                    <label class="form-control">
                </div>
            </div>

            <div class="rounded-2xl bg-base-100 border border-base-300 p-6 shadow space-y-4">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold">Defender</h2>
                    <span class="badge badge-outline" x-text="defender.nation_id ? `#${defender.nation_id}` : 'Manual'"></span>
                </div>
                <div class="rounded-xl bg-base-200/60 p-3 flex items-center gap-3">
                    <div class="avatar">
                        <div class="w-12 rounded-full ring ring-error/30 ring-offset-base-100 ring-offset-2">
                            <img :src="defender.flag || 'https://placehold.co/80x80?text=?'" alt="Defender flag"/>
                        </div>
                    </div>
                    <div class="flex-1">
                        <div class="font-semibold" x-text="defender.nation_name || 'Unknown nation'"></div>
                        <div class="text-xs text-base-content/60">
                            Leader: <span x-text="defender.leader_name || 'Unknown'"></span>
                        </div>
                        <div class="text-xs text-base-content/50">
                            Updated: <span x-text="formatDate(defender.last_updated) || 'Unknown'"></span>
                        </div>
                    </div>
                </div>
                <div class="space-y-3">
                    <div class="form-control">
                        <label class="label">
                            <span class="label-text text-xs uppercase tracking-wide">Load Active War</span>
                        </label>
                        <div class="flex gap-2">
                            <select class="select select-bordered flex-1" x-model="selectedWarId">
                                <option value="">Select war</option>
                                <template x-for="war in activeWars" :key="war.war_id">
                                    <option :value="war.war_id" x-text="`${war.war_id} • ${war.opponent_leader_name ?? 'Unknown'} (${war.war_type})`"></option>
                                </template>
                            </select>
                            <button class="btn btn-outline" @click="loadWar()">Load</button>
                        </div>
                    </div>
                    <div class="form-control">
                        <label class="label">
                            <span class="label-text text-xs uppercase tracking-wide">Fetch Nation ID</span>
                        </label>
                        <div class="flex gap-2">
                            <input type="number" min="1" x-model.number="lookupNationId" class="input input-bordered flex-1"/>
                            <button class="btn btn-outline" @click="loadNation()">Fetch</button>
                        </div>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <label class="form-control">
                        <span class="label-text text-xs">Soldiers</span>
                        <input type="number" min="0" x-model.number="defender.soldiers" class="input input-bordered"/>
                    </label>
                    <label class="form-control">
                        <span class="label-text text-xs">Tanks</span>
                        <input type="number" min="0" x-model.number="defender.tanks" class="input input-bordered"/>
                    </label>
                    <label class="form-control">
                        <span class="label-text text-xs">Aircraft</span>
                        <input type="number" min="0" x-model.number="defender.aircraft" class="input input-bordered"/>
                    </label>
                    <label class="form-control">
                        <span class="label-text text-xs">Ships</span>
                        <input type="number" min="0" x-model.number="defender.ships" class="input input-bordered"/>
                    </label>
                    <label class="form-control">
                        <span class="label-text text-xs">Cities</span>
                        <input type="number" min="0" x-model.number="defender.cities" class="input input-bordered"/>
                    </label>
                    <label class="form-control">
                        <span class="label-text text-xs">Highest Infra</span>
                        <input type="number" min="0" step="0.01" x-model.number="defender.highest_city_infra" class="input input-bordered"/>
                    </label>
                    <label class="form-control">
                        <span class="label-text text-xs tooltip" data-tip="Population from the highest-infra city. Used for resistance bonus.">Highest City Pop</span>
                        <input type="number" min="0" x-model.number="defender.highest_city_population" class="input input-bordered"/>
                    </label>
                    <label class="form-control">
                </div>
            </div>

            <div class="rounded-2xl bg-base-100 border border-base-300 p-6 shadow space-y-4">
                <h2 class="text-lg font-semibold">War Context</h2>
                <div class="space-y-3">
                    <label class="form-control">
                        <span class="label-text text-xs uppercase tracking-wide tooltip" data-tip="Controls infra and loot multipliers for both sides.">War Type</span>
                        <select class="select select-bordered" x-model="context.war_type">
                            <option value="ORDINARY">Ordinary</option>
                            <option value="ATTRITION">Attrition</option>
                            <option value="RAID">Raid</option>
                        </select>
                    </label>
                    <label class="form-control">
                        <span class="label-text text-xs uppercase tracking-wide tooltip" data-tip="Policies modify loot/infra multipliers. See Help.">Attacker Policy</span>
                        <select class="select select-bordered" x-model="attacker.war_policy">
                            <template x-for="policy in warPolicies" :key="policy">
                                <option :value="policy" x-text="policy"></option>
                            </template>
                        </select>
                    </label>
                    <label class="form-control">
                        <span class="label-text text-xs uppercase tracking-wide tooltip" data-tip="Policies modify loot/infra multipliers. See Help.">Defender Policy</span>
                        <select class="select select-bordered" x-model="defender.war_policy">
                            <template x-for="policy in warPolicies" :key="policy">
                                <option :value="policy" x-text="policy"></option>
                            </template>
                        </select>
                    </label>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="form-control">
                            <span class="label-text text-xs uppercase tracking-wide tooltip" data-tip="Owner halves opposing tank strength (0.5x).">Air Superiority</span>
                            <select class="select select-bordered" x-model="context.air_superiority_owner">
                                <option value="none">None</option>
                                <option value="attacker">Attacker</option>
                                <option value="defender">Defender</option>
                            </select>
                        </label>
                        <label class="form-control">
                            <span class="label-text text-xs uppercase tracking-wide tooltip" data-tip="Owner causes extra aircraft losses after ground wins.">Ground Control</span>
                            <select class="select select-bordered" x-model="context.ground_control_owner">
                                <option value="none">None</option>
                                <option value="attacker">Attacker</option>
                                <option value="defender">Defender</option>
                            </select>
                        </label>
                        <label class="form-control">
                            <span class="label-text text-xs uppercase tracking-wide tooltip" data-tip="Tracked for context only (no single-battle effect).">Blockade</span>
                            <select class="select select-bordered" x-model="context.blockade_owner">
                                <option value="none">None</option>
                                <option value="attacker">Attacker</option>
                                <option value="defender">Defender</option>
                            </select>
                        </label>
                        <label class="cursor-pointer flex items-center gap-2">
                            <input type="checkbox" class="checkbox checkbox-sm" x-model="defender.is_fortified"/>
                            <span class="text-sm tooltip" data-tip="Fortified defenders cause +25% attacker casualties.">Fortified defender</span>
                        </label>
                        <label class="cursor-pointer flex items-center gap-2">
                            <input type="checkbox" class="checkbox checkbox-sm" x-model="attacker.is_fortified"/>
                            <span class="text-sm tooltip" data-tip="Fortified attacker flag is stored for context only.">Fortified attacker</span>
                        </label>
                    </div>
                    <div class="flex flex-col gap-2">
                        <label class="cursor-pointer flex items-center gap-2">
                            <input type="checkbox" class="checkbox checkbox-sm" x-model="context.blitz_active_attacker"/>
                            <span class="text-sm tooltip" data-tip="Blitz adds +10% casualties and infra dealt for the acting side.">Blitz active (attacker)</span>
                        </label>
                        <label class="cursor-pointer flex items-center gap-2">
                            <input type="checkbox" class="checkbox checkbox-sm" x-model="context.blitz_active_defender"/>
                            <span class="text-sm tooltip" data-tip="Blitz adds +10% casualties and infra dealt for the acting side.">Blitz active (defender)</span>
                        </label>
                    </div>
                </div>
            </div>
        </div>
        <div class="rounded-2xl bg-base-100 border border-base-300 p-6 shadow space-y-4">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <h2 class="text-lg font-semibold">Attack Configuration</h2>
                <div class="flex flex-wrap items-center gap-2 text-xs text-base-content/60">
                    <span class="badge badge-outline">Defaults to attacker totals</span>
                    <span class="badge badge-outline">3-roll RNG per battle</span>
                </div>
            </div>
            <div class="tabs tabs-boxed">
                <a class="tab" :class="activeTab === 'ground' ? 'tab-active' : ''" @click="activeTab = 'ground'">Ground</a>
                <a class="tab" :class="activeTab === 'air' ? 'tab-active' : ''" @click="activeTab = 'air'">Air</a>
                <a class="tab" :class="activeTab === 'naval' ? 'tab-active' : ''" @click="activeTab = 'naval'">Naval</a>
            </div>
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <template x-if="activeTab === 'ground'">
                    <div class="space-y-3">
                        <label class="form-control">
                            <span class="label-text text-xs uppercase tracking-wide tooltip" data-tip="Defaults to attacker soldiers. You can exceed to simulate larger sends.">Attacking Soldiers</span>
                            <input type="number" min="0" x-model.number="action.attacking_soldiers" class="input input-bordered"/>
                        </label>
                        <label class="form-control">
                            <span class="label-text text-xs uppercase tracking-wide tooltip" data-tip="Defaults to attacker tanks. No max cap for hypothetical sims.">Attacking Tanks</span>
                            <input type="number" min="0" x-model.number="action.attacking_tanks" class="input input-bordered"/>
                        </label>
                        <label class="cursor-pointer flex items-center gap-2">
                            <input type="checkbox" class="checkbox checkbox-sm" x-model="action.arm_soldiers_with_munitions"/>
                            <span class="text-sm tooltip" data-tip="Armed soldiers use munitions and have higher combat value.">Arm soldiers with munitions</span>
                        </label>
                    </div>
                </template>
                <template x-if="activeTab === 'air'">
                    <div class="space-y-3">
                        <label class="form-control">
                            <span class="label-text text-xs uppercase tracking-wide tooltip" data-tip="Defaults to attacker aircraft.">Attacking Aircraft</span>
                            <input type="number" min="0" x-model.number="action.attacking_aircraft" class="input input-bordered"/>
                        </label>
                        <label class="form-control">
                            <span class="label-text text-xs uppercase tracking-wide tooltip" data-tip="Target controls non-air infra damage scaling and unit kill formulas.">Target</span>
                            <select class="select select-bordered" x-model="action.target">
                                <option value="infra">Infrastructure</option>
                                <option value="aircraft">Aircraft</option>
                                <option value="soldiers">Soldiers</option>
                                <option value="tanks">Tanks</option>
                                <option value="ships">Ships</option>
                                <option value="money">Money</option>
                            </select>
                        </label>
                    </div>
                </template>
                <template x-if="activeTab === 'naval'">
                    <div class="space-y-3">
                        <label class="form-control">
                            <span class="label-text text-xs uppercase tracking-wide tooltip" data-tip="Defaults to attacker ships.">Attacking Ships</span>
                            <input type="number" min="0" x-model.number="action.attacking_ships" class="input input-bordered"/>
                        </label>
                    </div>
                </template>
                <div class="lg:col-span-2 rounded-xl border border-base-300 bg-base-200/60 p-4">
                    <h3 class="text-sm font-semibold uppercase tracking-wide mb-2">Quick Notes</h3>
                    <ul class="text-sm text-base-content/70 space-y-1">
                        <li>3-roll RNG decides outcome tier (UF / PV / MS / IT).</li>
                        <li>Air superiority halves opposing tank strength for calculations.</li>
                        <li>Ground control applies post-battle aircraft damage on wins.</li>
                        <li>Loot caps apply only when defender cash is known.</li>
                        <li>Iterations + seed control precision and repeatability (see Help).</li>
                    </ul>
                </div>
            </div>
            <div class="flex flex-col gap-3 pt-2 lg:flex-row lg:items-center lg:justify-between">
                <button class="btn btn-ghost btn-sm" type="button" @click="syncAttackToAttacker()">
                    Use attacker totals
                </button>
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:gap-3">
                    <span class="text-xs text-base-content/60 tooltip" data-tip="Run uses the current iterations + seed settings.">Ready to simulate?</span>
                    <button class="btn btn-primary" type="button" @click="runSimulation()" :disabled="running">
                        <span x-show="!running">Run Simulation</span>
                        <span x-show="running" class="flex items-center gap-2">
                            <span class="loading loading-spinner loading-xs"></span>
                            Running...
                        </span>
                    </button>
                </div>
            </div>
        </div>

        <div class="rounded-2xl bg-base-100 border border-base-300 p-6 shadow space-y-6">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <h2 class="text-lg font-semibold">Results</h2>
                <div class="flex items-center gap-3">
                    <span class="text-xs text-base-content/60" x-show="copyStatus" x-text="copyStatus"></span>
                    <button class="btn btn-outline btn-sm" type="button" :disabled="!results" @click="copyJson()">Copy JSON</button>
                </div>
            </div>

            <template x-if="!results">
                <div class="text-sm text-base-content/60">Run a simulation to see probabilities, casualties, and cost breakdowns.</div>
            </template>

            <template x-if="results">
                <div class="space-y-6">
                    <div class="rounded-xl border border-base-300 p-4 space-y-4">
                        <div class="flex items-center justify-between">
                            <h3 class="text-sm font-semibold uppercase tracking-wide text-base-content/70">Outcome Spread</h3>
                            <span class="text-xs text-base-content/60">UF / PV / MS / IT</span>
                        </div>
                        <div class="rounded-full border border-base-300 bg-base-200/70 overflow-hidden">
                            <div class="flex h-3 w-full">
                                <template x-for="key in outcomeOrder" :key="key">
                                    <div :class="outcomeStyles[key].bar" :style="segmentStyle(key)"
                                         :title="`${key} ${formatPercent(outcomeProbability(key))}`"></div>
                                </template>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                            <template x-for="key in outcomeOrder" :key="key">
                                <div class="stat rounded-xl border border-base-300 bg-base-100/70"
                                     :class="`border-l-4 ${outcomeStyles[key].border}`">
                                    <div class="stat-title flex items-center gap-2">
                                        <span class="badge badge-sm" :class="outcomeStyles[key].badge" x-text="key"></span>
                                        <span x-text="outcomeLabels[key]"></span>
                                    </div>
                                    <div class="stat-value text-2xl" x-text="formatPercent(outcomeProbability(key))"></div>
                                </div>
                            </template>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
                        <div class="rounded-xl border border-base-300 p-4 space-y-3">
                            <h3 class="text-sm font-semibold uppercase tracking-wide text-success">Attacker Losses</h3>
                            <table class="table table-sm">
                                <thead>
                                <tr class="text-xs uppercase text-base-content/50">
                                    <th>Unit</th>
                                    <th class="text-right">Mean</th>
                                    <th class="text-right"><span class="tooltip" data-tip="10th percentile (10% of outcomes are at or below this).">p10</span></th>
                                    <th class="text-right"><span class="tooltip" data-tip="Median (50% of outcomes are at or below this).">p50</span></th>
                                    <th class="text-right"><span class="tooltip" data-tip="90th percentile (90% of outcomes are at or below this).">p90</span></th>
                                </tr>
                                </thead>
                                <tbody>
                                <template x-for="unit in ['soldiers','tanks','aircraft','ships']" :key="unit">
                                    <tr>
                                        <td class="capitalize" x-text="unit"></td>
                                        <td class="text-right" x-text="summaryField(results.metrics.attacker_losses[unit], 'mean')"></td>
                                        <td class="text-right" x-text="summaryField(results.metrics.attacker_losses[unit], 'p10')"></td>
                                        <td class="text-right" x-text="summaryField(results.metrics.attacker_losses[unit], 'p50')"></td>
                                        <td class="text-right" x-text="summaryField(results.metrics.attacker_losses[unit], 'p90')"></td>
                                    </tr>
                                </template>
                                </tbody>
                            </table>
                        </div>
                        <div class="rounded-xl border border-base-300 p-4 space-y-3">
                            <h3 class="text-sm font-semibold uppercase tracking-wide text-error">Defender Losses</h3>
                            <table class="table table-sm">
                                <thead>
                                <tr class="text-xs uppercase text-base-content/50">
                                    <th>Unit</th>
                                    <th class="text-right">Mean</th>
                                    <th class="text-right"><span class="tooltip" data-tip="10th percentile (10% of outcomes are at or below this).">p10</span></th>
                                    <th class="text-right"><span class="tooltip" data-tip="Median (50% of outcomes are at or below this).">p50</span></th>
                                    <th class="text-right"><span class="tooltip" data-tip="90th percentile (90% of outcomes are at or below this).">p90</span></th>
                                </tr>
                                </thead>
                                <tbody>
                                <template x-for="unit in ['soldiers','tanks','aircraft','ships']" :key="unit">
                                    <tr>
                                        <td class="capitalize" x-text="unit"></td>
                                        <td class="text-right" x-text="summaryField(results.metrics.defender_losses[unit], 'mean')"></td>
                                        <td class="text-right" x-text="summaryField(results.metrics.defender_losses[unit], 'p10')"></td>
                                        <td class="text-right" x-text="summaryField(results.metrics.defender_losses[unit], 'p50')"></td>
                                        <td class="text-right" x-text="summaryField(results.metrics.defender_losses[unit], 'p90')"></td>
                                    </tr>
                                </template>
                                </tbody>
                            </table>
                        </div>

                        <div class="rounded-xl border border-base-300 p-4 space-y-3">
                            <h3 class="text-sm font-semibold uppercase tracking-wide text-base-content/70">Loss Share (Mean)</h3>
                            <div class="space-y-3">
                                <template x-for="unit in ['soldiers','tanks','aircraft','ships']" :key="unit">
                                    <div class="space-y-1">
                                        <div class="flex items-center justify-between text-xs">
                                            <span class="capitalize" x-text="unit"></span>
                                            <span class="text-success">A: <span x-text="formatNumber(meanValue(results.metrics.attacker_losses[unit]))"></span></span>
                                            <span class="text-error">D: <span x-text="formatNumber(meanValue(results.metrics.defender_losses[unit]))"></span></span>
                                        </div>
                                        <div class="h-2 rounded-full bg-base-200 overflow-hidden flex">
                                            <div class="bg-success" :style="`width: ${lossShare(unit).attacker}%`"></div>
                                            <div class="bg-error" :style="`width: ${lossShare(unit).defender}%`"></div>
                                        </div>
                                    </div>
                                </template>
                            </div>
                            <p class="text-xs text-base-content/60">Bar shows attacker vs defender share of mean losses.</p>
                        </div>

                        <div class="rounded-xl border border-base-300 p-4 space-y-3">
                            <h3 class="text-sm font-semibold uppercase tracking-wide text-base-content/70">Damage & Loot</h3>
                            <table class="table table-sm">
                                <thead>
                                <tr class="text-xs uppercase text-base-content/50">
                                    <th>Metric</th>
                                    <th class="text-right">Mean</th>
                                    <th class="text-right"><span class="tooltip" data-tip="10th percentile (10% of outcomes are at or below this).">p10</span></th>
                                    <th class="text-right"><span class="tooltip" data-tip="Median (50% of outcomes are at or below this).">p50</span></th>
                                    <th class="text-right"><span class="tooltip" data-tip="90th percentile (90% of outcomes are at or below this).">p90</span></th>
                                </tr>
                                </thead>
                                <tbody>
                                <tr>
                                    <td>Infra destroyed</td>
                                    <td class="text-right" x-text="summaryField(results.metrics.infra_destroyed, 'mean')"></td>
                                    <td class="text-right" x-text="summaryField(results.metrics.infra_destroyed, 'p10')"></td>
                                    <td class="text-right" x-text="summaryField(results.metrics.infra_destroyed, 'p50')"></td>
                                    <td class="text-right" x-text="summaryField(results.metrics.infra_destroyed, 'p90')"></td>
                                </tr>
                                <tr>
                                    <td>$ looted</td>
                                    <td class="text-right" x-text="summaryFieldCurrency(results.metrics.money_looted, 'mean')"></td>
                                    <td class="text-right" x-text="summaryFieldCurrency(results.metrics.money_looted, 'p10')"></td>
                                    <td class="text-right" x-text="summaryFieldCurrency(results.metrics.money_looted, 'p50')"></td>
                                    <td class="text-right" x-text="summaryFieldCurrency(results.metrics.money_looted, 'p90')"></td>
                                </tr>
                                <tr>
                                    <td>$ destroyed</td>
                                    <td class="text-right" x-text="summaryFieldCurrency(results.metrics.money_destroyed, 'mean')"></td>
                                    <td class="text-right" x-text="summaryFieldCurrency(results.metrics.money_destroyed, 'p10')"></td>
                                    <td class="text-right" x-text="summaryFieldCurrency(results.metrics.money_destroyed, 'p50')"></td>
                                    <td class="text-right" x-text="summaryFieldCurrency(results.metrics.money_destroyed, 'p90')"></td>
                                </tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="rounded-xl border border-base-300 p-4 space-y-3">
                            <h3 class="text-sm font-semibold uppercase tracking-wide text-base-content/70">Resources & Costs</h3>
                            <table class="table table-sm">
                                <thead>
                                <tr class="text-xs uppercase text-base-content/50">
                                    <th>Metric</th>
                                    <th class="text-right text-success">Attacker</th>
                                    <th class="text-right text-error">Defender</th>
                                </tr>
                                </thead>
                                <tbody>
                                <tr>
                                    <td class="tooltip" :data-tip="priceTooltip('gasoline')">Gasoline used</td>
                                    <td class="text-right text-success" x-text="formatNumber(meanValue(results.metrics.resources_consumed_attacker.gasoline))"></td>
                                    <td class="text-right text-error" x-text="formatNumber(meanValue(results.metrics.resources_consumed_defender.gasoline))"></td>
                                </tr>
                                <tr>
                                    <td class="tooltip" :data-tip="priceTooltip('munitions')">Munitions used</td>
                                    <td class="text-right text-success" x-text="formatNumber(meanValue(results.metrics.resources_consumed_attacker.munitions))"></td>
                                    <td class="text-right text-error" x-text="formatNumber(meanValue(results.metrics.resources_consumed_defender.munitions))"></td>
                                </tr>
                                <tr>
                                    <td class="tooltip" :data-tip="priceTooltip('consumables')">Consumables value</td>
                                    <td class="text-right text-success" x-text="formatCurrency(meanValue(results.metrics.cost_estimates.consumables_value))"></td>
                                    <td class="text-right text-error" x-text="formatCurrency(meanValue(results.metrics.cost_estimates_defender.consumables_value))"></td>
                                </tr>
                                <tr>
                                    <td>Unit losses value</td>
                                    <td class="text-right text-success" x-text="formatCurrency(meanValue(results.metrics.cost_estimates.unit_losses_value))"></td>
                                    <td class="text-right text-error" x-text="formatCurrency(meanValue(results.metrics.cost_estimates_defender.unit_losses_value))"></td>
                                </tr>
                                <tr>
                                    <td>Total value</td>
                                    <td class="text-right text-success" x-text="formatCurrency(meanValue(results.metrics.cost_estimates.total_value))"></td>
                                    <td class="text-right text-error" x-text="formatCurrency(meanValue(results.metrics.cost_estimates_defender.total_value))"></td>
                                </tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="rounded-xl border border-base-300 p-4 space-y-3" x-show="results.metrics.improvement_destroy_chance">
                            <h3 class="text-sm font-semibold uppercase tracking-wide text-base-content/70">Improvement Destroy Chance</h3>
                            <table class="table table-sm">
                                <thead>
                                <tr class="text-xs uppercase text-base-content/50">
                                    <th>Metric</th>
                                    <th class="text-right">Mean</th>
                                    <th class="text-right"><span class="tooltip" data-tip="10th percentile (10% of outcomes are at or below this).">p10</span></th>
                                    <th class="text-right"><span class="tooltip" data-tip="Median (50% of outcomes are at or below this).">p50</span></th>
                                    <th class="text-right"><span class="tooltip" data-tip="90th percentile (90% of outcomes are at or below this).">p90</span></th>
                                </tr>
                                </thead>
                                <tbody>
                                <tr>
                                    <td>Chance (%)</td>
                                    <td class="text-right" x-text="summaryFieldPercent(results.metrics.improvement_destroy_chance, 'mean')"></td>
                                    <td class="text-right" x-text="summaryFieldPercent(results.metrics.improvement_destroy_chance, 'p10')"></td>
                                    <td class="text-right" x-text="summaryFieldPercent(results.metrics.improvement_destroy_chance, 'p50')"></td>
                                    <td class="text-right" x-text="summaryFieldPercent(results.metrics.improvement_destroy_chance, 'p90')"></td>
                                </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="rounded-xl border border-base-300 p-4">
                        <h3 class="text-sm font-semibold uppercase tracking-wide text-base-content/70 mb-2">Assumptions</h3>
                        <ul class="list-disc list-inside text-sm text-base-content/70 space-y-1">
                            <template x-for="note in results.assumptions" :key="note">
                                <li x-text="note"></li>
                            </template>
                        </ul>
                    </div>
                </div>
            </template>
        </div>

        <div class="rounded-2xl bg-base-100 border border-base-300 p-6 shadow space-y-6">
            <div class="flex items-center gap-3">
                <h2 class="text-lg font-semibold">Simulator Help & Formulas</h2>
                <span class="badge badge-outline">Everything explained</span>
            </div>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 text-sm">
                <div class="rounded-xl border border-base-300 p-4 space-y-2">
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-base-content/70">Glossary & Percentiles</h3>
                    <ul class="list-disc list-inside text-xs text-base-content/70 space-y-1">
                        <li>UF = Utter Failure, PV = Pyrrhic Victory, MS = Moderate Success, IT = Immense Triumph.</li>
                        <li>Mean is the average outcome across all iterations.</li>
                        <li>p10/p50/p90 are percentiles: 10%, 50% (median), and 90% of outcomes fall at or below that value.</li>
                        <li>All losses and damage are per simulated battle, not per war.</li>
                    </ul>
                </div>
                <div class="rounded-xl border border-base-300 p-4 space-y-2">
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-base-content/70">Iterations & Seed</h3>
                    <ul class="list-disc list-inside text-xs text-base-content/70 space-y-1">
                        <li>Iterations = how many simulated battles run (100-20000). Higher = smoother probabilities.</li>
                        <li>Seed makes RNG deterministic: same inputs + seed = identical results.</li>
                        <li>Blank seed uses true random entropy each run.</li>
                    </ul>
                </div>
                <div class="rounded-xl border border-base-300 p-4 space-y-2">
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-base-content/70">Core RNG & Outcome Tier</h3>
                    <p class="text-xs text-base-content/70">Each battle has three rolls. Each roll compares a random draw from 40% to 100% of each side's relevant force value.</p>
                    <div class="bg-base-200/60 rounded-lg p-3 font-mono text-[11px] leading-relaxed whitespace-pre-wrap">
roll(value) = RAND(0.4 * value, 1.0 * value)
3 rolls -> attacker wins = 0..3
0 = UF, 1 = PV, 2 = MS, 3 = IT
                    </div>
                </div>
                <div class="rounded-xl border border-base-300 p-4 space-y-2">
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-base-content/70">War Type & Policy Multipliers</h3>
                    <div class="bg-base-200/60 rounded-lg p-3 font-mono text-[11px] leading-relaxed whitespace-pre-wrap">
Ordinary: attacker infra 0.5 / loot 0.5 | defender infra 0.5 / loot 0.5
Attrition: attacker infra 1.0 / loot 0.25 | defender infra 1.0 / loot 0.5
Raid: attacker infra 0.25 / loot 1.0 | defender infra 0.5 / loot 1.0
                    </div>
                    <ul class="list-disc list-inside text-xs text-base-content/70 space-y-1">
                        <li>Loot policy: Pirate +40%, Moneybags -40% (stacked multiplicatively).</li>
                        <li>Infra policy: Attrition +10% dealt, Turtle -10% taken, Moneybags +5% taken, Covert/Arcane +5% taken.</li>
                        <li>Fortified defenders add +25% attacker casualties.</li>
                        <li>Blitz adds +10% casualties and infra dealt for the acting side.</li>
                    </ul>
                </div>
                <div class="rounded-xl border border-base-300 p-4 space-y-2">
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-base-content/70">Control States</h3>
                    <ul class="list-disc list-inside text-xs text-base-content/70 space-y-1">
                        <li>Air superiority halves opposing tank strength (0.5x) for calculations.</li>
                        <li>Ground control applies extra aircraft losses after a non-UF ground win.</li>
                        <li>Blockade is tracked for context only and does not change single-battle math.</li>
                    </ul>
                    <div class="bg-base-200/60 rounded-lg p-3 font-mono text-[11px] leading-relaxed whitespace-pre-wrap">
Ground control aircraft losses (after ground win):
IT: tanks_sent*0.005025
MS: tanks_sent*0.00335
PV: tanks_sent*0.001675
                    </div>
                </div>
                <div class="rounded-xl border border-base-300 p-4 space-y-2">
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-base-content/70">Population Resistance</h3>
                    <p class="text-xs text-base-content/70">Defender soldiers gain a resistance bonus based on the highest-infra city:</p>
                    <div class="bg-base-200/60 rounded-lg p-3 font-mono text-[11px] leading-relaxed whitespace-pre-wrap">
DS_effective = DS + (highest_city_population / 400)
                    </div>
                    <p class="text-xs text-base-content/70">Resistance only affects rolls; casualties are clamped to real defender soldiers.</p>
                    <p class="text-xs text-base-content/70">If per-city population is unavailable, population is estimated from city infra share.</p>
                </div>
                <div class="rounded-xl border border-base-300 p-4 space-y-2">
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-base-content/70">Ground Battle (Formulas)</h3>
                    <p class="text-xs text-base-content/70">Defender soldiers are assumed armed. Attacker soldiers are armed only if toggled.</p>
                    <div class="bg-base-200/60 rounded-lg p-3 font-mono text-[11px] leading-relaxed whitespace-pre-wrap">
AttArmy = AS_unarmed*1 + AS_armed*1.75 + AT*40
DefArmy = DS_armed*1.75 + DT*40 (DS includes resistance bonus)

Per roll:
ATSR = roll(attacker soldier value) | DTSR = roll(defender soldier value)
ATTR = roll(attacker tank value)    | DTTR = roll(defender tank value)
AR = ATSR + ATTR | DR = DTSR + DTTR

If AR > DR:
  Att tank losses += DTSR*0.0004060606 + DTTR*0.00066666666
  Def tank losses += ATSR*0.00043225806 + ATTR*0.00070967741
Else:
  Att tank losses += DTSR*0.00043225806 + DTTR*0.00070967741
  Def tank losses += ATSR*0.0004060606 + ATTR*0.00066666666

Always:
  Att soldier losses += DTSR*0.0084 + DTTR*0.0092
  Def soldier losses += ATSR*0.0084 + ATTR*0.0092
                    </div>
                    <p class="text-xs text-base-content/70">Ground infra:</p>
                    <div class="bg-base-200/60 rounded-lg p-3 font-mono text-[11px] leading-relaxed whitespace-pre-wrap">
Infra = MAX(MIN(((AS - DS*0.5)*0.000606061 + (AT - DT*0.5)*0.01)
       * RAND(0.85,1.05) * (Victory/3), highest_infra*0.2 + 25), 0)
                    </div>
                    <p class="text-xs text-base-content/70">Ground loot:</p>
                    <div class="bg-base-200/60 rounded-lg p-3 font-mono text-[11px] leading-relaxed whitespace-pre-wrap">
MaxLoot = (AS*1.1 + AT*25.15) * Victory * RAND(0.8,1.1) * warTypeLoot * policyLoot
Known cash: min(MaxLoot, cash*0.75, cash-$1,000,000). Unknown cash = MaxLoot.
                    </div>
                    <p class="text-xs text-base-content/70">Ground improvement destroy chance (IT only):</p>
                    <div class="bg-base-200/60 rounded-lg p-3 font-mono text-[11px] leading-relaxed whitespace-pre-wrap">
Base 10%, Pirate x2, Tactician x2, Guardian x0.5 (cap 100%)
                    </div>
                </div>
                <div class="rounded-xl border border-base-300 p-4 space-y-2">
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-base-content/70">Airstrikes (Formulas)</h3>
                    <div class="bg-base-200/60 rounded-lg p-3 font-mono text-[11px] leading-relaxed whitespace-pre-wrap">
AirValue = aircraft * 3
Per roll:
  Dogfight (target aircraft):
    Att losses += def_roll*0.01
    Def losses += att_roll*0.018337
  Other targets:
    Att losses += def_roll*0.015385
    Def losses += att_roll*0.009091
                    </div>
                    <p class="text-xs text-base-content/70">Air infra:</p>
                    <div class="bg-base-200/60 rounded-lg p-3 font-mono text-[11px] leading-relaxed whitespace-pre-wrap">
Infra = MAX(MIN((att_air - def_air*0.5) * 0.35353535 * RAND(0.85,1.05)
       * (Victory/3), highest_infra*0.5 + 100), 0)
Non-infra target: infra / 3
                    </div>
                    <p class="text-xs text-base-content/70">Target kills (IT, then scaled by victory):</p>
                    <div class="bg-base-200/60 rounded-lg p-3 font-mono text-[11px] leading-relaxed whitespace-pre-wrap">
Soldiers: MIN(enemy, enemy*0.75+1000, (att_air - def_air*0.5)*35*RAND)
Tanks:    MIN(enemy, enemy*0.75+10,   (att_air - def_air*0.5)*1.25*RAND)
Ships:    MIN(enemy, enemy*0.5+4,     (att_air - def_air*0.5)*0.0285*RAND)
Scaling: IT 100%, MS 70%, PV 40%, UF 0%
                    </div>
                    <p class="text-xs text-base-content/70">$ money-destroy formula for air target is not specified, so output is null.</p>
                </div>
                <div class="rounded-xl border border-base-300 p-4 space-y-2">
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-base-content/70">Naval (Formulas)</h3>
                    <div class="bg-base-200/60 rounded-lg p-3 font-mono text-[11px] leading-relaxed whitespace-pre-wrap">
NavyValue = ships * 4
Per roll:
  Att ship losses += def_roll*0.01375
  Def ship losses += att_roll*0.01375
Infra = MAX(MIN((att_ships - def_ships*0.5) * 2.625 * RAND(0.85,1.05)
       * (Victory/3), highest_infra*0.5 + 25), 0)
                    </div>
                    <p class="text-xs text-base-content/70">Improvement destroy chance (IT only):</p>
                    <div class="bg-base-200/60 rounded-lg p-3 font-mono text-[11px] leading-relaxed whitespace-pre-wrap">
Base 15%, Pirate x2, Tactician x2, Guardian x0.5 (cap 100%)
                    </div>
                </div>
                <div class="rounded-xl border border-base-300 p-4 space-y-2">
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-base-content/70">Resources & Costing</h3>
                    <div class="bg-base-200/60 rounded-lg p-3 font-mono text-[11px] leading-relaxed whitespace-pre-wrap">
Ground: gas = tanks*0.01, munitions = tanks*0.01 + (armed soldiers*0.0002)
Air:    gas = aircraft*0.25, munitions = aircraft*0.25
Naval:  gas = ships*2, munitions = ships*3
                    </div>
                    <ul class="list-disc list-inside text-xs text-base-content/70 space-y-1">
                        <li>Consumables value uses 24h avg prices (TradePriceService).</li>
                        <li>Unit loss value: soldier $5, tank $60 + 0.5 steel, aircraft $4000 + 5 aluminum, ship $50000 + 30 steel.</li>
                        <li>Infra value is not currently modeled in totals.</li>
                    </ul>
                </div>
                <div class="rounded-xl border border-base-300 p-4 space-y-2">
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-base-content/70">Data & Privacy</h3>
                    <ul class="list-disc list-inside text-xs text-base-content/70 space-y-1">
                        <li>Highest-infra city data is used for infra caps and population resistance.</li>
                        <li>Manual edits always override fetched values.</li>
                        <li>Attacking inputs default to attacker totals but can be increased for hypothetical sends.</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('warSim', () => ({
                loading: true,
                running: false,
                errorMessage: null,
                defaults: null,
                activeWars: [],
                selectedWarId: '',
                lookupNationId: null,
                lookupAttackerId: null,
                iterations: 5000,
                seed: null,
                activeTab: 'ground',
                copyStatus: null,
                outcomeOrder: ['UF', 'PV', 'MS', 'IT'],
                outcomeStyles: {
                    UF: { badge: 'badge-error', bar: 'bg-error', border: 'border-error' },
                    PV: { badge: 'badge-warning', bar: 'bg-warning', border: 'border-warning' },
                    MS: { badge: 'badge-secondary', bar: 'bg-secondary', border: 'border-secondary' },
                    IT: { badge: 'badge-success', bar: 'bg-success', border: 'border-success' },
                },
                outcomeLabels: {
                    UF: 'Utter Failure',
                    PV: 'Pyrrhic Victory',
                    MS: 'Moderate Success',
                    IT: 'Immense Triumph',
                },
                warPolicies: ['NONE', 'ATTRITION', 'PIRATE', 'MONEYBAGS', 'TURTLE', 'COVERT', 'ARCANE', 'TACTICIAN', 'GUARDIAN'],
                attacker: {
                    nation_id: null,
                    nation_name: null,
                    leader_name: null,
                    flag: null,
                    last_updated: null,
                    soldiers: 0,
                    tanks: 0,
                    aircraft: 0,
                    ships: 0,
                    war_policy: 'NONE',
                    is_fortified: false,
                    cities: 0,
                    highest_city_infra: 0,
                    highest_city_population: 0,
                    avg_infra: null,
                },
                defender: {
                    nation_id: null,
                    nation_name: null,
                    leader_name: null,
                    flag: null,
                    last_updated: null,
                    soldiers: 0,
                    tanks: 0,
                    aircraft: 0,
                    ships: 0,
                    war_policy: 'NONE',
                    is_fortified: false,
                    cities: 0,
                    highest_city_infra: 0,
                    highest_city_population: 0,
                    avg_infra: null,
                },
                context: {
                    war_type: 'ORDINARY',
                    attacker_policy: 'NONE',
                    defender_policy: 'NONE',
                    air_superiority_owner: 'none',
                    ground_control_owner: 'none',
                    blockade_owner: 'none',
                    blitz_active_attacker: false,
                    blitz_active_defender: false,
                },
                action: {
                    type: 'ground',
                    attacking_soldiers: 0,
                    attacking_tanks: 0,
                    arm_soldiers_with_munitions: true,
                    attacking_aircraft: 0,
                    target: 'infra',
                    attacking_ships: 0,
                },
                results: null,
                init() {
                    this.fetchDefaults();
                },
                normalizeIterations() {
                    if (this.iterations < 100) {
                        this.iterations = 100;
                    }
                    if (this.iterations > 20000) {
                        this.iterations = 20000;
                    }
                },
                async fetchDefaults() {
                    this.loading = true;
                    try {
                        const response = await fetch('/api/v1/simulators/war/defaults', { headers: this.apiHeaders() });
                        if (!response.ok) {
                            throw new Error('Failed to load defaults.');
                        }
                        const data = await response.json();
                        this.defaults = data;
                        if (data.nation) {
                            this.applyNation(data.nation, 'attacker');
                        }
                        this.activeWars = data.active_wars ?? [];
                    } catch (error) {
                        this.errorMessage = error.message ?? 'Unable to load simulator defaults.';
                    } finally {
                        this.loading = false;
                    }
                },
                async loadNation() {
                    if (!this.lookupNationId) {
                        this.errorMessage = 'Enter a nation ID to fetch.';
                        return;
                    }
                    try {
                        const response = await fetch(`/api/v1/simulators/nations/${this.lookupNationId}`, { headers: this.apiHeaders() });
                        if (!response.ok) {
                            throw new Error('Nation not found.');
                        }
                        const data = await response.json();
                        if (data.nation) {
                            this.applyNation(data.nation, 'defender');
                        }
                        this.errorMessage = null;
                    } catch (error) {
                        this.errorMessage = error.message ?? 'Unable to fetch nation.';
                    }
                },
                async loadWar() {
                    if (!this.selectedWarId) {
                        this.errorMessage = 'Select a war to load.';
                        return;
                    }
                    try {
                        const response = await fetch(`/api/v1/simulators/wars/${this.selectedWarId}`, { headers: this.apiHeaders() });
                        if (!response.ok) {
                            throw new Error('War not found or unauthorized.');
                        }
                        const data = await response.json();
                        if (data.attacker) {
                            this.applyNation(data.attacker, 'attacker');
                        }
                        if (data.defender) {
                            this.applyNation(data.defender, 'defender');
                        }
                        if (data.context) {
                            this.context = data.context;
                        }
                        this.errorMessage = null;
                    } catch (error) {
                        this.errorMessage = error.message ?? 'Unable to load war.';
                    }
                },
                async loadAttacker() {
                    if (!this.lookupAttackerId) {
                        this.errorMessage = 'Enter a nation ID to fetch.';
                        return;
                    }
                    try {
                        const response = await fetch(`/api/v1/simulators/nations/${this.lookupAttackerId}`, { headers: this.apiHeaders() });
                        if (!response.ok) {
                            throw new Error('Nation not found.');
                        }
                        const data = await response.json();
                        if (data.nation) {
                            this.applyNation(data.nation, 'attacker');
                        }
                        this.errorMessage = null;
                    } catch (error) {
                        this.errorMessage = error.message ?? 'Unable to fetch nation.';
                    }
                },
                applyNation(nation, side) {
                    const { money, ...payload } = nation;
                    this[side] = {
                        ...this[side],
                        ...payload,
                    };
                    if (side === 'attacker') {
                        this.syncAttackToAttacker();
                    }
                },
                syncAttackToAttacker() {
                    this.action.attacking_soldiers = this.attacker.soldiers;
                    this.action.attacking_tanks = this.attacker.tanks;
                    this.action.attacking_aircraft = this.attacker.aircraft;
                    this.action.attacking_ships = this.attacker.ships;
                },
                buildPayload() {
                    return {
                        iterations: this.iterations,
                        seed: this.seed ?? null,
                        nation_attacker: this.attacker,
                        nation_defender: this.defender,
                        context: {
                            ...this.context,
                            attacker_policy: this.attacker.war_policy,
                            defender_policy: this.defender.war_policy,
                        },
                        action: {
                            ...this.action,
                            type: this.activeTab,
                        },
                    };
                },
                async runSimulation() {
                    this.running = true;
                    this.errorMessage = null;
                    this.results = null;
                    this.copyStatus = null;
                    try {
                        const response = await fetch('/api/v1/simulators/run', {
                            method: 'POST',
                            headers: this.apiHeaders(true),
                            body: JSON.stringify(this.buildPayload()),
                        });
                        if (!response.ok) {
                            const payload = await response.json();
                            throw new Error(payload.message ?? 'Simulation failed.');
                        }
                        this.results = await response.json();
                    } catch (error) {
                        this.errorMessage = error.message ?? 'Simulation failed.';
                    } finally {
                        this.running = false;
                    }
                },
                apiHeaders(includeJson = false) {
                    const headers = {
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'Accept': 'application/json',
                    };
                    if (includeJson) {
                        headers['Content-Type'] = 'application/json';
                    }
                    return headers;
                },
                formatPercent(value) {
                    return `${Number(value).toFixed(2)}%`;
                },
                outcomeProbability(key) {
                    return this.results?.outcomes?.probabilities?.[key] ?? 0;
                },
                segmentStyle(key) {
                    const value = this.outcomeProbability(key);
                    return `width: ${Number(value)}%`;
                },
                formatSummary(summary) {
                    if (!summary) {
                        return '—';
                    }
                    const mean = this.formatNumber(summary.mean);
                    const p10 = this.formatNumber(summary.p10);
                    const p50 = this.formatNumber(summary.p50);
                    const p90 = this.formatNumber(summary.p90);
                    return `${mean} (p10 ${p10} / p50 ${p50} / p90 ${p90})`;
                },
                formatNumber(value) {
                    if (value === null || value === undefined) {
                        return '—';
                    }
                    return Number(value).toLocaleString(undefined, { maximumFractionDigits: 2 });
                },
                formatCurrency(value) {
                    if (value === null || value === undefined) {
                        return '—';
                    }
                    return `$${Number(value).toLocaleString(undefined, { maximumFractionDigits: 2 })}`;
                },
                formatDate(value) {
                    if (!value) {
                        return null;
                    }
                    const date = new Date(value);
                    if (Number.isNaN(date.getTime())) {
                        return null;
                    }
                    return date.toLocaleString();
                },
                summaryField(summary, field) {
                    if (!summary || summary[field] === null || summary[field] === undefined) {
                        return '—';
                    }
                    return this.formatNumber(summary[field]);
                },
                summaryFieldCurrency(summary, field) {
                    if (!summary || summary[field] === null || summary[field] === undefined) {
                        return '—';
                    }
                    return this.formatCurrency(summary[field]);
                },
                summaryFieldPercent(summary, field) {
                    if (!summary || summary[field] === null || summary[field] === undefined) {
                        return '—';
                    }
                    return `${this.formatNumber(summary[field])}%`;
                },
                meanValue(summary) {
                    if (!summary || summary.mean === null || summary.mean === undefined) {
                        return 0;
                    }
                    return summary.mean;
                },
                priceTooltip(resource) {
                    if (!this.results?.meta?.prices) {
                        return 'Price unavailable';
                    }
                    const prices = this.results.meta.prices;
                    if (resource === 'consumables') {
                        const gas = prices.gasoline ?? null;
                        const mun = prices.munitions ?? null;
                        return `Gasoline: ${this.formatCurrency(gas)} / unit • Munitions: ${this.formatCurrency(mun)} / unit`;
                    }
                    const price = prices[resource];
                    if (price === null || price === undefined) {
                        return 'Price unavailable';
                    }
                    return `${this.formatCurrency(price)} per unit (24h avg)`;
                },
                lossShare(unit) {
                    const attacker = this.meanValue(this.results?.metrics?.attacker_losses?.[unit]);
                    const defender = this.meanValue(this.results?.metrics?.defender_losses?.[unit]);
                    const total = attacker + defender;
                    if (total <= 0) {
                        return { attacker: 0, defender: 0 };
                    }
                    return {
                        attacker: (attacker / total) * 100,
                        defender: (defender / total) * 100,
                    };
                },
                async copyJson() {
                    if (!this.results) {
                        return;
                    }
                    const payload = JSON.stringify(this.results, null, 2);
                    try {
                        if (navigator.clipboard && window.isSecureContext) {
                            await navigator.clipboard.writeText(payload);
                            this.copyStatus = 'Copied to clipboard.';
                            return;
                        }
                        const textarea = document.createElement('textarea');
                        textarea.value = payload;
                        textarea.setAttribute('readonly', 'readonly');
                        textarea.style.position = 'absolute';
                        textarea.style.left = '-9999px';
                        document.body.appendChild(textarea);
                        textarea.select();
                        const success = document.execCommand('copy');
                        document.body.removeChild(textarea);
                        this.copyStatus = success ? 'Copied to clipboard.' : 'Copy failed.';
                    } catch (error) {
                        this.copyStatus = 'Copy failed. Try again or use HTTPS.';
                    }
                },
            }));
        });
    </script>
@endpush
