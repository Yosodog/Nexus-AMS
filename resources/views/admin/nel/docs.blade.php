@extends('layouts.admin')

@section('title', 'NEL Documentation')

@section('content')
    <x-header :title="config('app.name') . ' Expression Language (NEL)'" separator>
        <x-slot:subtitle>
            Define safe, predictable expressions for audits, grants, and MMR checks using dot-notation like
            <code>nation.score</code> or <code>nation.military.soldiers</code>.
        </x-slot:subtitle>
        <x-slot:actions>
            <x-badge value="Sandboxed & read-only" icon="o-shield-check" class="badge-primary badge-sm" />
        </x-slot:actions>
    </x-header>

    <div class="grid gap-6 lg:grid-cols-2">
        <x-card title="Overview">
            <ul class="space-y-3 text-sm">
                <li>Dot-notation walks the variable tree you provide, for example <code>nation.military.soldiers</code>.</li>
                <li>Supports numbers, strings, booleans, null, arithmetic, comparisons, and boolean logic.</li>
                <li>Function hooks are supported via helpers and stay server-registered.</li>
                <li>Evaluation is pure: no database, filesystem, or network access.</li>
                <li>Backed by the NEL core in <code>App\Nel</code> and adapter profiles such as <code>nation.*</code>.</li>
            </ul>
        </x-card>

        <x-card title="Quick Start">
            <div class="space-y-4 text-sm">
                <div>
                    <div class="text-xs font-semibold uppercase tracking-wide text-base-content/60">Variables &amp; paths</div>
                    <p><code>nation.score</code>, <code>nation.soldiers</code>, <code>city.infrastructure</code>, <code>city.powered</code></p>
                </div>
                <div>
                    <div class="text-xs font-semibold uppercase tracking-wide text-base-content/60">Literals</div>
                    <p>Numbers, strings, booleans, and <code>null</code>.</p>
                </div>
                <div>
                    <div class="text-xs font-semibold uppercase tracking-wide text-base-content/60">Operators</div>
                    <p>Comparison: <code>==</code>, <code>!=</code>, <code>&lt;</code>, <code>&lt;=</code>, <code>&gt;</code>, <code>&gt;=</code></p>
                    <p>Boolean: <code>&amp;&amp;</code>, <code>||</code>, <code>!</code></p>
                    <p>Arithmetic: <code>+</code>, <code>-</code>, <code>*</code>, <code>/</code>, <code>%</code>, parentheses <code>( )</code></p>
                </div>
            </div>
        </x-card>

        <x-card title="Operator Precedence">
            <div class="overflow-x-auto rounded-box border border-base-300">
                <table class="table table-sm">
                    <thead>
                    <tr>
                        <th class="text-xs uppercase tracking-wide text-base-content/60">Level</th>
                        <th class="text-xs uppercase tracking-wide text-base-content/60">Operators</th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr><td>1 (highest)</td><td><code>( )</code></td></tr>
                    <tr><td>2</td><td><code>!</code>, unary <code>-</code></td></tr>
                    <tr><td>3</td><td><code>*</code>, <code>/</code>, <code>%</code></td></tr>
                    <tr><td>4</td><td><code>+</code>, <code>-</code></td></tr>
                    <tr><td>5</td><td><code>&lt;</code>, <code>&lt;=</code>, <code>&gt;</code>, <code>&gt;=</code>, <code>==</code>, <code>!=</code></td></tr>
                    <tr><td>6</td><td><code>&amp;&amp;</code></td></tr>
                    <tr><td>7 (lowest)</td><td><code>||</code></td></tr>
                    </tbody>
                </table>
            </div>
            <p class="mt-3 text-sm text-base-content/60">All binary operators are left-associative. Parentheses win.</p>
        </x-card>

        <x-card title="Examples">
            <div class="space-y-4 text-sm">
                <div>
                    <div class="text-xs font-semibold uppercase tracking-wide text-base-content/60">Readiness checks</div>
                    <pre class="rounded-box bg-base-200 p-4 text-xs"><code>nation.score &gt; 500 &amp;&amp; nation.military.soldiers &gt; 10000
city.infrastructure % 50 != 0 || city.land % 50 != 0
!(nation.score &gt;= 1000)</code></pre>
                </div>
                <div>
                    <div class="text-xs font-semibold uppercase tracking-wide text-base-content/60">Future helper shape</div>
                    <pre class="rounded-box bg-base-200 p-4 text-xs"><code>hasProject("Nuclear Research")
double(nation.score)</code></pre>
                    <p class="text-base-content/60">Helpers are registered server-side; unknown helpers throw an error.</p>
                </div>
            </div>
        </x-card>
    </div>

    <x-card title="Audit-focused Reference" class="mt-6">
        <div class="grid gap-6 lg:grid-cols-2">
            <div class="space-y-3 text-sm">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-base-content/60">Nation rule variables</h3>
                <p class="text-base-content/60">Available under <code>nation.*</code> when target type = nation. Violations trigger when the expression evaluates to <code>true</code>.</p>
                <ul class="list-disc space-y-2 pl-5">
                    <li><code>nation.score</code>, <code>nation.num_cities</code>, <code>nation.color</code>, <code>nation.continent</code></li>
                    <li>Alliance: <code>nation.alliance_id</code>, <code>nation.alliance_position</code></li>
                    <li>Economy: <code>nation.money</code>, <code>nation.steel</code>, <code>nation.food</code>, <code>nation.credits</code></li>
                    <li>Military: <code>nation.soldiers</code>, <code>nation.tanks</code>, <code>nation.aircraft</code>, <code>nation.ships</code>, <code>nation.missiles</code>, <code>nation.nukes</code>, <code>nation.spies</code></li>
                    <li>Activity: <code>nation.account_credits</code>, <code>nation.last_active</code>, <code>nation.account_discord_id</code></li>
                    <li>War stats: <code>nation.wars_won</code>, <code>nation.wars_lost</code>, <code>nation.offensive_wars_count</code>, <code>nation.defensive_wars_count</code></li>
                    <li>Projects: <code>nation.projects_count</code>, <code>nation.project_bits</code></li>
                    <li>MMR: <code>nation.mmr_score</code></li>
                </ul>
                <pre class="rounded-box bg-base-200 p-4 text-xs"><code>// Example: enforce aircraft per city
nation.aircraft &gt;= nation.num_cities * 50</code></pre>
            </div>

            <div class="space-y-3 text-sm">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-base-content/60">City rule variables</h3>
                <p class="text-base-content/60">Available under <code>city.*</code> plus minimal <code>nation.*</code> context when target type = city.</p>
                <ul class="list-disc space-y-2 pl-5">
                    <li>Basics: <code>city.id</code>, <code>city.name</code>, <code>city.infrastructure</code>, <code>city.land</code>, <code>city.powered</code></li>
                    <li>Power: <code>city.oil_power</code>, <code>city.wind_power</code>, <code>city.coal_power</code>, <code>city.nuclear_power</code></li>
                    <li>Improvements: farms, barracks, hospitals, recycling centers, factories, hangars, drydocks, mines, and refineries</li>
                    <li>Parent nation context: <code>nation.id</code>, <code>nation.nation_name</code>, <code>nation.leader_name</code>, <code>nation.score</code>, <code>nation.num_cities</code>, <code>nation.color</code></li>
                </ul>
                <pre class="rounded-box bg-base-200 p-4 text-xs"><code>// Example: infra/land alignment
city.infrastructure % 50 == 0 &amp;&amp; city.land % 50 == 0

// Example: ensure power
city.powered == true || city.nuclear_power &gt; 0</code></pre>
            </div>
        </div>
    </x-card>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <x-card title="Nation Helpers">
            <p class="text-sm"><strong><code>nation.has_project(name)</code></strong> checks whether the nation owns a specific project.</p>
            <p class="mt-2 text-sm text-base-content/60">Projects are matched using the Politics &amp; War project list built into <code>PWHelperService::projects()</code>.</p>
            <pre class="mt-4 rounded-box bg-base-200 p-4 text-xs"><code>nation.has_project("Iron Dome")
nation.has_project("Urban Planning") &amp;&amp; !nation.has_project("Advanced Pirate Economy")</code></pre>
        </x-card>

        <x-card title="City Helpers">
            <p class="text-sm"><strong><code>city.improvements_count()</code></strong> returns the total count of all city improvements.</p>
            <pre class="mt-4 rounded-box bg-base-200 p-4 text-xs"><code>city.improvements_count() &gt;= 35
city.improvements_count() == city.num_cities * 3</code></pre>
        </x-card>

        <x-card title="Math Helpers">
            <p class="text-sm"><strong><code>math.floor_to_multiple(value, multiple)</code></strong> rounds a number down to the nearest multiple.</p>
            <pre class="mt-4 rounded-box bg-base-200 p-4 text-xs"><code>// Slot alignment
math.floor_to_multiple(city.infrastructure, 50) / 50 &gt;= city.improvements_count()

// Round down a score to the nearest hundred
math.floor_to_multiple(nation.score, 100)</code></pre>
            <p class="mt-2 text-sm text-base-content/60">Throws an error if <code>multiple</code> is zero.</p>
        </x-card>

        <x-card title="Validation &amp; Error Handling">
            <ul class="list-disc space-y-2 pl-5 text-sm">
                <li>Rules are parsed on save; syntax errors return inline validation.</li>
                <li>Unknown variables throw errors during evaluation; keep names exact.</li>
                <li>Unknown helpers also throw errors because helpers are registered server-side.</li>
            </ul>
            <div class="alert alert-warning mt-4">
                <span class="text-sm">Keep expressions pure: avoid division by zero and guard nulls with comparisons that tolerate missing values.</span>
            </div>
        </x-card>
    </div>

    <x-card title="Patterns for Audits" class="mt-6">
        <pre class="rounded-box bg-base-200 p-4 text-xs"><code>// Stockpile safety
nation.food &lt; 500000 || nation.steel &lt; 100000

// Readiness by city count
nation.aircraft &lt; nation.num_cities * 50

// Activity check (timestamp seconds)
nation.last_active &lt; 1700000000</code></pre>
        <ul class="mt-4 list-disc space-y-2 pl-5 text-sm">
            <li>Use integer math for thresholds; no date helpers are available in NEL.</li>
            <li>Prefer <code>&amp;&amp;</code> to narrow matches and <code>||</code> for exceptions.</li>
            <li>Keep expressions short and rely on descriptive rule names in the UI.</li>
        </ul>
    </x-card>

    <x-card title="Project Bits Generator" class="mt-6" x-data="projectBitsGenerator({{ Illuminate\Support\Js::from($projects) }})">
        <x-slot:menu>
            <div class="flex gap-2">
                <button type="button" class="btn btn-primary btn-outline btn-sm" @click="selectAll">Select All</button>
                <button type="button" class="btn btn-ghost btn-sm" @click="clearAll">Clear All</button>
            </div>
        </x-slot:menu>

        <div class="grid gap-6 lg:grid-cols-[minmax(0,1.2fr)_minmax(0,0.8fr)]">
            <div class="overflow-x-auto rounded-box border border-base-300">
                <table class="table table-sm">
                    <thead>
                    <tr>
                        <th class="text-xs uppercase tracking-wide text-base-content/60">Project</th>
                        <th class="text-right text-xs uppercase tracking-wide text-base-content/60">Bit Value</th>
                    </tr>
                    </thead>
                    <tbody>
                    <template x-for="([name, bit], index) in Object.entries(projects)" :key="name">
                        <tr>
                            <td>
                                <label class="flex items-center gap-3">
                                    <input class="checkbox checkbox-primary checkbox-sm" type="checkbox" :id="'project-' + index" :value="name" x-model="selected">
                                    <span x-text="name"></span>
                                </label>
                            </td>
                            <td class="text-right">
                                <span class="badge badge-ghost" x-text="bit.toString()"></span>
                            </td>
                        </tr>
                    </template>
                    </tbody>
                </table>
            </div>

            <div class="space-y-4">
                <label class="block space-y-2">
                    <span class="text-sm font-medium">Bit Integer</span>
                    <input type="text" class="input input-bordered w-full" :value="bitsString" readonly>
                </label>
                <label class="block space-y-2">
                    <span class="text-sm font-medium">Binary</span>
                    <input type="text" class="input input-bordered w-full font-mono" :value="binaryString" readonly>
                </label>
                <label class="block space-y-2">
                    <span class="text-sm font-medium">Selected</span>
                    <input type="text" class="input input-bordered w-full" :value="selected.length ? selected.join(', ') : 'None'" readonly>
                </label>

                <div class="rounded-box border border-base-300 bg-base-200/60 p-4">
                    <div class="mb-3 flex items-center justify-between">
                        <span class="font-semibold">Selected Projects</span>
                        <span class="badge badge-primary" x-text="selected.length + ' / ' + Object.keys(projects).length"></span>
                    </div>
                    <template x-if="selected.length">
                        <div class="space-y-2">
                            <template x-for="name in selected" :key="name">
                                <div class="rounded-box border border-base-300 bg-base-100 px-3 py-2 text-sm" x-text="name"></div>
                            </template>
                        </div>
                    </template>
                    <p class="text-sm text-base-content/60" x-show="!selected.length">No projects selected.</p>
                </div>
            </div>
        </div>
    </x-card>

    @push('scripts')
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
