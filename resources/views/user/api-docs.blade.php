@extends('layouts.main')

@section('content')
    <div class="mx-auto max-w-6xl space-y-8">
        <div class="rounded-3xl border border-base-200 bg-gradient-to-br from-primary/10 via-base-100 to-warning/10 p-6 shadow-sm">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="space-y-3">
                    <div class="flex flex-wrap items-center gap-2 text-xs uppercase tracking-wide text-base-content/60">
                        <span class="badge badge-outline">API</span>
                        <span class="badge badge-outline">User Access</span>
                        <span class="badge badge-outline">v1</span>
                    </div>
                    <h1 class="text-3xl font-bold leading-tight sm:text-4xl">{{ config('app.name') }} API reference</h1>
                    <p class="text-sm text-base-content/70 max-w-2xl">
                        Use your personal access token to read data and request deposits. Discord bot and
                        subscription endpoints are intentionally omitted from this page.
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <a href="{{ route('user.settings') }}" class="btn btn-outline btn-sm">Back to settings</a>
                    <span class="badge badge-primary badge-outline">JSON only</span>
                </div>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            <x-utils.card>
                <div class="space-y-3">
                    <h2 class="text-lg font-semibold">Quick start</h2>
                    <ol class="list-decimal list-inside text-sm text-base-content/70 space-y-2">
                        <li>Create a token in Settings.</li>
                        <li>Send it as a Bearer token in the Authorization header.</li>
                        <li>Call the endpoints below from your app or scripts.</li>
                    </ol>
                </div>
            </x-utils.card>

            <x-utils.card>
                <div class="space-y-3">
                    <h2 class="text-lg font-semibold">Authentication</h2>
                    <p class="text-sm text-base-content/70">All endpoints require a personal access token.</p>
                    <div class="rounded-box bg-base-200 p-3 font-mono text-xs text-base-content/80">
                        Authorization: Bearer &lt;token&gt;
                    </div>
                    <p class="text-xs text-base-content/60">Requests without a valid token return 401.</p>
                </div>
            </x-utils.card>

            <x-utils.card>
                <div class="space-y-3">
                    <h2 class="text-lg font-semibold">Base URL</h2>
                    <div class="rounded-box bg-base-200 p-3 font-mono text-xs text-base-content/80 break-all">
                        {{ url('/api/v1') }}
                    </div>
                    <p class="text-xs text-base-content/60">Set Accept: application/json for consistent responses.</p>
                </div>
            </x-utils.card>
        </div>

        <x-utils.card>
            <div class="space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold">Endpoints</h2>
                        <p class="text-sm text-base-content/70">Admin-only routes require admin access in addition to a token.</p>
                    </div>
                    <span class="badge badge-outline">v1</span>
                </div>

                <div class="overflow-x-auto">
                    <table class="table table-zebra table-sm">
                        <thead>
                            <tr>
                                <th>Method</th>
                                <th>Path</th>
                                <th>Description</th>
                                <th>Access</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><span class="badge badge-outline">GET</span></td>
                                <td class="font-mono text-xs">/user</td>
                                <td class="text-sm">Return the authenticated user profile.</td>
                                <td class="text-xs">Token</td>
                            </tr>
                            <tr>
                                <td><span class="badge badge-outline">GET</span></td>
                                <td class="font-mono text-xs">/accounts</td>
                                <td class="text-sm">List accounts linked to the authenticated user.</td>
                                <td class="text-xs">Token</td>
                            </tr>
                            <tr>
                                <td><span class="badge badge-outline">POST</span></td>
                                <td class="font-mono text-xs">/accounts/{account}/deposit-request</td>
                                <td class="text-sm">Create or reuse a pending deposit request for an account.</td>
                                <td class="text-xs">Token</td>
                            </tr>
                            <tr>
                                <td><span class="badge badge-outline">GET</span></td>
                                <td class="font-mono text-xs">/defense/raid-finder/{nation_id?}</td>
                                <td class="text-sm">Fetch raid finder targets for a nation.</td>
                                <td class="text-xs">Token</td>
                            </tr>
                            <tr>
                                <td><span class="badge badge-outline">GET</span></td>
                                <td class="font-mono text-xs">/simulators/war/defaults</td>
                                <td class="text-sm">Load war simulator defaults (your nation + active wars).</td>
                                <td class="text-xs">Token</td>
                            </tr>
                            <tr>
                                <td><span class="badge badge-outline">GET</span></td>
                                <td class="font-mono text-xs">/simulators/nations/{nationId}</td>
                                <td class="text-sm">Fetch a nation snapshot for simulator inputs.</td>
                                <td class="text-xs">Token</td>
                            </tr>
                            <tr>
                                <td><span class="badge badge-outline">GET</span></td>
                                <td class="font-mono text-xs">/simulators/wars/{warId}</td>
                                <td class="text-sm">Fetch war context + attacker/defender snapshots.</td>
                                <td class="text-xs">Token</td>
                            </tr>
                            <tr>
                                <td><span class="badge badge-outline">POST</span></td>
                                <td class="font-mono text-xs">/simulators/run</td>
                                <td class="text-sm">Run a war simulation (ground/air/naval).</td>
                                <td class="text-xs">Token</td>
                            </tr>
                            <tr>
                                <td><span class="badge badge-outline">GET</span></td>
                                <td class="font-mono text-xs">/members</td>
                                <td class="text-sm">List alliance members with resources and discord context.</td>
                                <td class="text-xs">Admin</td>
                            </tr>
                            <tr>
                                <td><span class="badge badge-outline">GET</span></td>
                                <td class="font-mono text-xs">/war-plans/{plan}/targets</td>
                                <td class="text-sm">Target list plus recommended assignment data.</td>
                                <td class="text-xs">Admin</td>
                            </tr>
                            <tr>
                                <td><span class="badge badge-outline">GET</span></td>
                                <td class="font-mono text-xs">/war-plans/{plan}/assignments</td>
                                <td class="text-sm">Assignments for a war plan.</td>
                                <td class="text-xs">Admin</td>
                            </tr>
                            <tr>
                                <td><span class="badge badge-outline">GET</span></td>
                                <td class="font-mono text-xs">/war-plans/{plan}/friendlies</td>
                                <td class="text-sm">Friendly nation data and assignment stats.</td>
                                <td class="text-xs">Admin</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </x-utils.card>

        <div class="grid gap-6 lg:grid-cols-2">
            <x-utils.card>
                <div class="space-y-3">
                    <h3 class="text-lg font-semibold">Example request</h3>
                    <div class="rounded-box bg-base-200 p-4 font-mono text-xs text-base-content/80 overflow-x-auto">
                        <pre><code>curl -H "Authorization: Bearer YOUR_TOKEN" \
     -H "Accept: application/json" \
     {{ url('/api/v1/accounts') }}</code></pre>
                    </div>
                    <p class="text-xs text-base-content/60">Replace YOUR_TOKEN with the token created in Settings.</p>
                </div>
            </x-utils.card>

            <x-utils.card>
                <div class="space-y-3">
                    <h3 class="text-lg font-semibold">Deposit request response</h3>
                    <div class="rounded-box bg-base-200 p-4 font-mono text-xs text-base-content/80 overflow-x-auto">
                        <pre><code>{
    "message": "Deposit request created successfully.",
    "deposit_code": "ABC123"
}</code></pre>
                    </div>
                    <p class="text-xs text-base-content/60">The deposit code stays the same while a request is pending.</p>
                </div>
            </x-utils.card>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <x-utils.card>
                <div class="space-y-3">
                    <h3 class="text-lg font-semibold">War simulator request</h3>
                    <div class="rounded-box bg-base-200 p-4 font-mono text-xs text-base-content/80 overflow-x-auto">
                        <pre><code>POST {{ url('/api/v1/simulators/run') }}
Authorization: Bearer YOUR_TOKEN
Accept: application/json

{
  "iterations": 5000,
  "seed": null,
  "nation_attacker": {
    "nation_id": 1234,
    "soldiers": 50000,
    "tanks": 2000,
    "aircraft": 600,
    "ships": 30,
    "war_policy": "PIRATE",
    "is_fortified": false,
    "cities": 10,
    "highest_city_infra": 1800,
    "highest_city_population": 900000,
    "avg_infra": 1700
  },
  "nation_defender": {
    "nation_id": 5678,
    "soldiers": 30000,
    "tanks": 1200,
    "aircraft": 400,
    "ships": 20,
    "war_policy": "MONEYBAGS",
    "is_fortified": true,
    "cities": 9,
    "highest_city_infra": 1600,
    "highest_city_population": 800000,
    "avg_infra": 1550
  },
  "context": {
    "war_type": "ORDINARY",
    "attacker_policy": "PIRATE",
    "defender_policy": "MONEYBAGS",
    "air_superiority_owner": "none",
    "ground_control_owner": "none",
    "blockade_owner": "none",
    "blitz_active_attacker": false,
    "blitz_active_defender": false
  },
  "action": {
    "type": "ground",
    "attacking_soldiers": 50000,
    "attacking_tanks": 2000,
    "arm_soldiers_with_munitions": true
  }
}</code></pre>
                    </div>
                    <p class="text-xs text-base-content/60">The simulator is rate-limited; reduce iterations if you hit throttles.</p>
                </div>
            </x-utils.card>

            <x-utils.card>
                <div class="space-y-3">
                    <h3 class="text-lg font-semibold">War simulator response</h3>
                    <div class="rounded-box bg-base-200 p-4 font-mono text-xs text-base-content/80 overflow-x-auto">
                        <pre><code>{
  "meta": {
    "iterations": 5000,
    "seed": null,
    "generated_at": "2026-01-27T19:42:00Z",
    "prices": {
      "gasoline": 3200,
      "munitions": 2600,
      "steel": 3800,
      "aluminum": 4200
    }
  },
  "outcomes": {
    "probabilities": {
      "UF": 12.34,
      "PV": 28.91,
      "MS": 38.45,
      "IT": 20.30
    }
  },
  "metrics": {
    "attacker_losses": { "soldiers": { "mean": 1234, "p10": 820, "p50": 1200, "p90": 1600 } },
    "defender_losses": { "soldiers": { "mean": 2100, "p10": 1500, "p50": 2000, "p90": 2700 } },
    "infra_destroyed": { "mean": 45.2, "p10": 20.1, "p50": 42.5, "p90": 70.8 },
    "money_looted": { "mean": 1250000, "p10": 550000, "p50": 1100000, "p90": 1900000 },
    "resources_consumed_attacker": { "gasoline": { "mean": 20 }, "munitions": { "mean": 30 } },
    "resources_consumed_defender": { "gasoline": { "mean": 12 }, "munitions": { "mean": 18 } },
    "cost_estimates": { "consumables_value": { "mean": 200000 }, "unit_losses_value": { "mean": 900000 } },
    "cost_estimates_defender": { "consumables_value": { "mean": 120000 }, "unit_losses_value": { "mean": 700000 } }
  },
  "assumptions": [
    "Each battle uses three RNG rolls with uniform 40%-100% force multipliers."
  ]
}</code></pre>
                    </div>
                    <p class="text-xs text-base-content/60">Response values are per simulated battle, not per war.</p>
                </div>
            </x-utils.card>
        </div>
    </div>
@endsection
