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
                    <h1 class="text-4xl font-bold leading-tight">Nexus API reference</h1>
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
                    <div class="rounded-box bg-base-200 p-4 font-mono text-xs text-base-content/80">
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
                    <div class="rounded-box bg-base-200 p-4 font-mono text-xs text-base-content/80">
                        <pre><code>{
    "message": "Deposit request created successfully.",
    "deposit_code": "ABC123"
}</code></pre>
                    </div>
                    <p class="text-xs text-base-content/60">The deposit code stays the same while a request is pending.</p>
                </div>
            </x-utils.card>
        </div>
    </div>
@endsection
