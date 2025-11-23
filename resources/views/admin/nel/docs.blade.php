@extends('layouts.admin')

@section('title', 'NEL Documentation')

@section('content')
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col-12 col-lg-8">
                    <h3 class="mb-1">Nexus Expression Language (NEL)</h3>
                    <p class="text-secondary mb-0">
                        Define safe, predictable expressions for audits, grants, and MMR checks using dot-notation like
                        <code>nation.score</code> or <code>nation.military.soldiers</code>.
                    </p>
                </div>
                <div class="col-12 col-lg-4 mt-3 mt-lg-0 text-lg-end">
                    <span class="badge bg-primary-subtle text-primary-emphasis">
                        <i class="bi bi-shield-lock me-1"></i> Sandboxed &amp; read-only
                    </span>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-6">
                    <div class="card shadow-sm h-100">
                        <div class="card-header fw-semibold">
                            Overview
                        </div>
                        <div class="card-body">
                            <ul class="list-unstyled mb-0">
                        <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-1"></i> Dot-notation walks the
                            variable tree you provide (e.g., <code>nation.military.soldiers</code>).
                        </li>
                        <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-1"></i> Supports numbers, strings,
                            booleans, null, arithmetic, comparisons, and boolean logic.
                        </li>
                        <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-1"></i> Function hooks are supported
                            via helpers (future-ready; no domain helpers ship yet).
                        </li>
                        <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-1"></i> Evaluation is pure: no DB,
                            filesystem, or network access.
                        </li>
                        <li class="mb-0"><i class="bi bi-check-circle-fill text-success me-1"></i> Backed by the NEL core in
                            <code>App\Nel</code> and adapter profiles (e.g., nation → <code>nation.*</code>).
                        </li>
                    </ul>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
                        <div class="card shadow-sm h-100">
                            <div class="card-header fw-semibold">
                                Quick start
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <div class="fw-semibold text-secondary text-uppercase small">Variables &amp; paths</div>
                                    <p class="mb-1"><code>nation.score</code>, <code>nation.soldiers</code>,
                                        <code>city.infrastructure</code>, <code>city.powered</code></p>
                                </div>
                                <div class="mb-3">
                                    <div class="fw-semibold text-secondary text-uppercase small">Literals</div>
                                    <p class="mb-1">Numbers (<code>1200</code>, <code>3.14</code>), strings (<code>"text"</code> or
                                        <code>'text'</code>), booleans (<code>true</code>, <code>false</code>), null (<code>null</code>).
                                    </p>
                                </div>
                                <div class="mb-0">
                                    <div class="fw-semibold text-secondary text-uppercase small">Operators</div>
                                    <p class="mb-1">Comparison: <code>==</code>, <code>!=</code>, <code>&lt;</code>,
                                        <code>&lt;=</code>, <code>&gt;</code>, <code>&gt;=</code></p>
                                    <p class="mb-1">Boolean: <code>&amp;&amp;</code>, <code>||</code>, <code>!</code></p>
                                    <p class="mb-0">Arithmetic: <code>+</code>, <code>-</code>, <code>*</code>, <code>/</code>,
                                        <code>%</code>, parentheses <code>( )</code></p>
                                </div>
                            </div>
                        </div>
                    </div>
        </div>

    <div class="row g-3 mt-1">
        <div class="col-lg-6">
                <div class="card shadow-sm h-100">
                    <div class="card-header fw-semibold">
                        Operator Precedence
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm mb-0 align-middle">
                            <thead>
                            <tr>
                                <th scope="col" class="text-secondary text-uppercase small">Level</th>
                                <th scope="col" class="text-secondary text-uppercase small">Operators</th>
                            </tr>
                            </thead>
                            <tbody>
                            <tr>
                                <td>1 (highest)</td>
                                <td><code>( )</code></td>
                            </tr>
                            <tr>
                                <td>2</td>
                                <td><code>!</code>, unary <code>-</code></td>
                            </tr>
                            <tr>
                                <td>3</td>
                                <td><code>*</code>, <code>/</code>, <code>%</code></td>
                            </tr>
                            <tr>
                                <td>4</td>
                                <td><code>+</code>, <code>-</code></td>
                            </tr>
                            <tr>
                                <td>5</td>
                                <td><code>&lt;</code>, <code>&lt;=</code>, <code>&gt;</code>, <code>&gt;=</code>,
                                    <code>==</code>, <code>!=</code></td>
                            </tr>
                            <tr>
                                <td>6</td>
                                <td><code>&amp;&amp;</code></td>
                            </tr>
                            <tr>
                                <td>7 (lowest)</td>
                                <td><code>||</code></td>
                            </tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="text-secondary small mb-0 mt-2">All binary operators are left-associative. Parentheses win.</p>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
                <div class="card shadow-sm h-100">
                    <div class="card-header fw-semibold">
                        Examples
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <div class="fw-semibold text-secondary text-uppercase small">Readiness checks</div>
                        <pre class="bg-body-secondary p-3 rounded mb-0"><code>nation.score &gt; 500 &amp;&amp; nation.military.soldiers &gt; 10000
city.infrastructure % 50 != 0 || city.land % 50 != 0
!(nation.score &gt;= 1000)</code></pre>
                    </div>
                    <div>
                        <div class="fw-semibold text-secondary text-uppercase small">Future helper shape</div>
                        <pre class="bg-body-secondary p-3 rounded mb-0"><code>hasProject("Nuclear Research")
double(nation.score)</code></pre>
                        <p class="text-secondary small mb-0 mt-1">Helpers are registered server-side; unknown helpers throw
                            an error.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mt-1">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header fw-semibold">
                    Audit-focused reference
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-lg-6">
                            <h6 class="text-uppercase text-secondary small fw-semibold">Nation rule variables (flat)</h6>
                            <p class="text-muted small mb-2">Available under <code>nation.*</code> when target type = nation. <strong>Violation triggers when the expression evaluates to <code>true</code>.</strong></p>
                            <ul class="mb-3">
                                <li><code>nation.score</code>, <code>nation.num_cities</code>, <code>nation.color</code>, <code>nation.continent</code></li>
                                <li>Alliance: <code>nation.alliance_id</code>, <code>nation.alliance_position</code></li>
                                <li>Economy: <code>nation.money</code>, <code>nation.steel</code>, <code>nation.food</code>, <code>nation.credits</code></li>
                                <li>Military: <code>nation.soldiers</code>, <code>nation.tanks</code>, <code>nation.aircraft</code>, <code>nation.ships</code>, <code>nation.missiles</code>, <code>nation.nukes</code>, <code>nation.spies</code></li>
                                <li>Activity: <code>nation.account_credits</code>, <code>nation.last_active</code> (Unix timestamp), <code>nation.account_discord_id</code></li>
                                <li>War stats: <code>nation.wars_won</code>, <code>nation.wars_lost</code>, <code>nation.offensive_wars_count</code>, <code>nation.defensive_wars_count</code></li>
                                <li>Projects: <code>nation.projects_count</code>, <code>nation.project_bits</code></li>
                                <li>MMR: <code>nation.mmr_score</code> (latest snapshot)</li>
                            </ul>
                            <pre class="bg-body-secondary p-3 rounded small mb-0"><code>// Example: enforce aircraft per city
nation.aircraft &gt;= nation.num_cities * 50</code></pre>
                        </div>
                        <div class="col-lg-6">
                            <h6 class="text-uppercase text-secondary small fw-semibold">City rule variables</h6>
                            <p class="text-muted small mb-2">Available under <code>city.*</code> (plus a minimal <code>nation.*</code> context) when target type = city. <strong>Violation triggers when the expression returns <code>true</code>.</strong></p>
                            <ul class="mb-3">
                                <li>Basics: <code>city.id</code>, <code>city.name</code>, <code>city.infrastructure</code>, <code>city.land</code>, <code>city.powered</code></li>
                                <li>Power: <code>city.oil_power</code>, <code>city.wind_power</code>, <code>city.coal_power</code>, <code>city.nuclear_power</code></li>
                                <li>Improvements: <code>city.farm</code>, <code>city.barracks</code>, <code>city.hospital</code>, <code>city.recycling_center</code>, <code>city.factory</code>, <code>city.hangar</code>, <code>city.drydock</code>, and all mines/refineries listed in the mapper.</li>
                                <li>Parent nation context: <code>nation.id</code>, <code>nation.nation_name</code>, <code>nation.leader_name</code>, <code>nation.score</code>, <code>nation.num_cities</code>, <code>nation.color</code></li>
                            </ul>
                            <pre class="bg-body-secondary p-3 rounded small mb-0"><code>// Example: infra/land alignment
city.infrastructure % 50 == 0 &amp;&amp; city.land % 50 == 0

// Example: ensure power
city.powered == true || city.nuclear_power &gt; 0</code></pre>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mt-1">
        <div class="col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header fw-semibold">
                    Validation &amp; error handling
                </div>
                <div class="card-body">
                    <ul class="mb-3">
                        <li>Rules are parsed on save; syntax errors return inline validation.</li>
                        <li>Unknown variables (e.g., <code>nation.foo</code>) throw errors during evaluation; keep names exact.</li>
                        <li>No helper functions are registered yet—callable helpers will be added in future releases.</li>
                    </ul>
                    <div class="alert alert-warning mb-0">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        Keep expressions pure: avoid division by zero and guard nulls (e.g., <code>nation.aircraft ?? 0</code> is not supported; use comparisons that tolerate nulls).
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header fw-semibold">
                    Patterns for audits
                </div>
                <div class="card-body">
                    <pre class="bg-body-secondary p-3 rounded small mb-3"><code>// Stockpile safety
nation.food &lt; 500000 || nation.steel &lt; 100000

// Readiness by city count
nation.aircraft &lt; nation.num_cities * 50

// Activity check (timestamp seconds)
nation.last_active &lt; (now - 86400) // not supported directly; compare against a literal timestamp</code></pre>
                    <p class="text-muted small mb-2">Tips:</p>
                    <ul class="mb-0">
                        <li>Use integer math for thresholds; no date helpers are available in NEL.</li>
                        <li>Prefer combining conditions with <code>&amp;&amp;</code> to narrow matches; use <code>||</code> for exceptions.</li>
                        <li>Keep expressions short and add descriptive rule names for clarity in the UI.</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mt-1">
        <div class="col-12">
            <div class="card shadow-sm" x-data="projectBitsGenerator({{ Illuminate\Support\Js::from($projects) }})">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <div class="fw-semibold">Project Bits Generator</div>
                        <small class="text-secondary">Toggle PW projects to compute the project_bits integer in real time.</small>
                    </div>
                    <div class="btn-group btn-group-sm" role="group" aria-label="Project bulk actions">
                        <button type="button" class="btn btn-outline-primary" @click="selectAll">Select All</button>
                        <button type="button" class="btn btn-outline-secondary" @click="clearAll">Clear All</button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-lg-6">
                            <div class="table-responsive border rounded">
                                <table class="table table-sm align-middle mb-0">
                                    <thead class="table-light">
                                    <tr>
                                        <th scope="col" class="text-secondary text-uppercase small">Project</th>
                                        <th scope="col" class="text-secondary text-uppercase small text-end">Bit Value</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <template x-for="([name, bit], index) in Object.entries(projects)" :key="name">
                                        <tr>
                                            <td>
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" :id="'project-'+index" :value="name" x-model="selected">
                                                    <label class="form-check-label" :for="'project-'+index" x-text="name"></label>
                                                </div>
                                            </td>
                                            <td class="text-end">
                                                <span class="badge bg-secondary-subtle text-secondary-emphasis" x-text="bit.toString()"></span>
                                            </td>
                                        </tr>
                                    </template>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="col-lg-6">
                            <div class="input-group mb-3">
                                <span class="input-group-text">Bit Integer</span>
                                <input type="text" class="form-control" :value="bitsString" readonly>
                            </div>
                            <div class="input-group mb-3">
                                <span class="input-group-text">Binary</span>
                                <input type="text" class="form-control font-monospace" :value="binaryString" readonly>
                            </div>
                            <div class="input-group mb-3">
                                <span class="input-group-text">Selected</span>
                                <input type="text" class="form-control" :value="selected.length ? selected.join(', ') : 'None'" readonly>
                            </div>
                            <div class="border rounded p-3 bg-body-secondary">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="fw-semibold">Selected Projects</span>
                                    <span class="badge bg-primary-subtle text-primary-emphasis" x-text="selected.length + ' / ' + Object.keys(projects).length"></span>
                                </div>
                                <template x-if="selected.length">
                                    <ul class="list-group list-group-flush">
                                        <template x-for="name in selected" :key="name">
                                            <li class="list-group-item py-1" x-text="name"></li>
                                        </template>
                                    </ul>
                                </template>
                                <p class="text-secondary mb-0" x-show="!selected.length">No projects selected.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @push('scripts')
        <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.2/dist/cdn.min.js" defer></script>
        <script>
            function projectBitsGenerator(projects) {
                const normalizedProjects = Object.fromEntries(
                    Object.entries(projects).map(([name, bit]) => [name, BigInt(bit)])
                );

                return {
                    projects: normalizedProjects,
                    selected: [],
                    selectAll() {
                        this.selected = Object.keys(this.projects);
                    },
                    clearAll() {
                        this.selected = [];
                    },
                    get bits() {
                        return this.selected.reduce((total, name) => total | (this.projects[name] ?? 0n), 0n);
                    },
                    get bitsString() {
                        return this.bits.toString();
                    },
                    get binaryString() {
                        const value = this.bits;

                        return value ? value.toString(2) : '0';
                    },
                };
            }
        </script>
    @endpush
@endsection
