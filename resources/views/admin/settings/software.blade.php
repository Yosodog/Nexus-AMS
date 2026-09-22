@extends('admin.settings.layout')

@section('settings-title', 'Software')
@section('settings-subtitle', 'Update Nexus and manage the components installed on this server.')

@section('settings-content')
    @php
        $updater = $snapshot['updater'] ?? [];
        $updates = $snapshot['updates'] ?? [];
        $components = $snapshot['components'] ?? [];
        $operations = $snapshot['operations'] ?? [];
        $cleanup = $snapshot['cleanup'] ?? ['reclaimable_bytes' => 0, 'entries' => []];
        $operationId = session('system-operation-id');
    @endphp

    <div
        class="space-y-6"
        data-software-page
        @if ($operationId)
            data-operation-url="{{ route('admin.settings.software.operations.show', ['operation' => $operationId]) }}"
        @endif
    >
        @if (($updater['available'] ?? false) !== true)
            <div class="alert alert-warning" role="alert">
                <span>The Nexus updater is unavailable. Run <code>sudo nexus doctor</code> on this server for details.</span>
            </div>
        @endif

        <section class="nexus-panel space-y-5 p-5" aria-labelledby="release-heading">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <h2 id="release-heading" class="text-lg font-semibold">Nexus update</h2>
                    <p class="text-sm text-base-content/65">Updates Core and every installed local component together.</p>
                </div>
                <div class="stats stats-vertical border border-base-300 bg-base-100 shadow-sm sm:stats-horizontal">
                    <div class="stat py-3">
                        <div class="stat-title text-xs">Installed</div>
                        <div class="stat-value text-lg">{{ $updates['installed_release'] ?? $updater['installed_release_id'] ?? 'Not installed' }}</div>
                    </div>
                    <div class="stat py-3">
                        <div class="stat-title text-xs">Latest</div>
                        <div class="stat-value text-lg">{{ $updates['latest_release'] ?? 'Unknown' }}</div>
                    </div>
                </div>
            </div>

            @if (($updates['update_available'] ?? false) === true)
                <div class="rounded-box border border-info/30 bg-info/10 p-4">
                    <p class="font-semibold">An update is available.</p>
                    @if (! empty($updates['releases']))
                        <p class="mt-1 text-sm text-base-content/70">
                            Releases applied in order:
                            {{ collect($updates['releases'])->pluck('release_id')->filter()->implode(', ') }}
                        </p>
                    @endif
                    @if (collect($updates['releases'] ?? [])->contains(fn ($release) => ! empty($release['release_notes'])))
                        <details class="mt-3">
                            <summary class="cursor-pointer font-medium">Release notes</summary>
                            <div class="mt-3 space-y-4 text-sm text-base-content/75">
                                @foreach ($updates['releases'] as $release)
                                    @if (! empty($release['release_notes']))
                                        <section>
                                            <h3 class="font-semibold text-base-content">{{ $release['release_id'] ?? 'Release' }}</h3>
                                            <div class="mt-1 whitespace-pre-wrap">{{ $release['release_notes'] }}</div>
                                        </section>
                                    @endif
                                @endforeach
                            </div>
                        </details>
                    @endif
                </div>
            @else
                <p class="text-sm text-base-content/70">{{ isset($updates['error_code']) ? 'Update information could not be refreshed.' : 'This server is on the latest available release.' }}</p>
            @endif

            @can('manage-system')
                <div class="flex flex-wrap gap-3">
                    <form method="POST" action="{{ route('admin.settings.software.updates.start') }}" onsubmit="return confirm('Update Nexus and restart its services now?');">
                        @csrf
                        <input type="hidden" name="confirmation" value="1">
                        <button type="submit" class="btn btn-primary" @disabled(($updates['update_available'] ?? false) !== true)>Update Nexus</button>
                    </form>
                    <form method="POST" action="{{ route('admin.settings.software.rollback') }}" onsubmit="return confirm('Switch Nexus code back to the immediately previous release? Database migrations will not be reversed.');">
                        @csrf
                        <input type="hidden" name="confirmation" value="1">
                        <button type="submit" class="btn btn-outline btn-warning" @disabled(empty($updater['previous_release_id']))>Roll back code</button>
                    </form>
                </div>
            @endcan
        </section>

        <section class="nexus-panel space-y-4 p-5" aria-labelledby="components-heading">
            <div>
                <h2 id="components-heading" class="text-lg font-semibold">Components on this server</h2>
                <p class="text-sm text-base-content/65">Optional remote components are shown for visibility but must be managed with the CLI on their own host.</p>
            </div>

            <div class="grid gap-4 lg:grid-cols-3">
                @foreach ($components as $component)
                    @php
                        $componentId = (string) ($component['component_id'] ?? '');
                        $actions = is_array($component['available_actions'] ?? null) ? $component['available_actions'] : [];
                    @endphp
                    <article class="rounded-box border border-base-300 bg-base-100 p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h3 class="font-semibold">{{ $component['label'] ?? $componentId }}</h3>
                                <p class="text-xs text-base-content/60">{{ ($component['required'] ?? false) ? 'Required' : 'Optional' }} · {{ $component['host_location'] ?? 'This server' }}</p>
                            </div>
                            <span class="badge {{ ($component['health_state'] ?? '') === 'healthy' ? 'badge-success' : 'badge-ghost' }}">
                                {{ str($component['health_state'] ?? $component['runtime_state'] ?? 'unknown')->headline() }}
                            </span>
                        </div>

                        <dl class="mt-4 grid grid-cols-2 gap-2 text-sm">
                            <dt class="text-base-content/60">Installed</dt><dd>{{ ($component['installed'] ?? false) ? 'Yes' : 'No' }}</dd>
                            <dt class="text-base-content/60">Enabled</dt><dd>{{ ($component['enabled'] ?? false) ? 'Yes' : 'No' }}</dd>
                            <dt class="text-base-content/60">Release</dt><dd>{{ $component['release_id'] ?? '—' }}</dd>
                            <dt class="text-base-content/60">Configuration</dt><dd>{{ str($component['configuration_state'] ?? 'unknown')->headline() }}</dd>
                        </dl>

                        @can('manage-system')
                            <div class="mt-4 flex flex-wrap gap-2">
                                @foreach ($actions as $action)
                                    @if (! in_array($action, ['install', 'enable', 'disable', 'restart'], true))
                                        @continue
                                    @endif

                                    @if ($action === 'install' && $componentId === 'nexus-discord')
                                        <details class="w-full rounded-box border border-base-300 p-3">
                                            <summary class="cursor-pointer text-sm font-medium">Install Discord</summary>
                                            <form class="mt-3 space-y-3" method="POST" action="{{ route('admin.settings.software.components.action', ['component' => $componentId, 'action' => $action]) }}" onsubmit="return confirm('Install Nexus Discord on this server?');">
                                                @csrf
                                                <input type="hidden" name="confirmation" value="1">
                                                <label class="form-control">
                                                    <span class="label-text text-xs">Bot token</span>
                                                    <input class="input input-bordered input-sm" type="password" name="configuration[bot_token]" autocomplete="new-password" required>
                                                </label>
                                                <label class="form-control">
                                                    <span class="label-text text-xs">Application ID</span>
                                                    <input class="input input-bordered input-sm" name="configuration[client_id]" inputmode="numeric" pattern="[0-9]{17,20}" required>
                                                </label>
                                                <label class="form-control">
                                                    <span class="label-text text-xs">Guild ID</span>
                                                    <input class="input input-bordered input-sm" name="configuration[guild_id]" inputmode="numeric" pattern="[0-9]{17,20}" required>
                                                </label>
                                                <button class="btn btn-sm btn-primary" type="submit">Install</button>
                                            </form>
                                        </details>
                                    @else
                                        <form method="POST" action="{{ route('admin.settings.software.components.action', ['component' => $componentId, 'action' => $action]) }}" onsubmit="return confirm('Run this component operation now?');">
                                            @csrf
                                            <input type="hidden" name="confirmation" value="1">
                                            <button class="btn btn-sm {{ $action === 'disable' ? 'btn-ghost' : 'btn-outline' }}" type="submit">{{ ucfirst($action) }}</button>
                                        </form>
                                    @endif
                                @endforeach
                            </div>
                        @endcan
                    </article>
                @endforeach
            </div>
        </section>

        <section class="nexus-panel space-y-4 p-5" aria-labelledby="cleanup-heading">
            <div>
                <h2 id="cleanup-heading" class="text-lg font-semibold">Cleanup old deployments</h2>
                <p class="text-sm text-base-content/65">Current and previous releases, credentials, uploads, database data, and writable storage are always protected.</p>
            </div>
            <p class="text-sm">
                {{ number_format(((int) ($cleanup['reclaimable_bytes'] ?? 0)) / 1024 / 1024, 1) }} MiB can be reclaimed from
                {{ count($cleanup['entries'] ?? []) }} managed item(s).
            </p>
            @if (! empty($cleanup['entries']))
                <details>
                    <summary class="cursor-pointer text-sm font-medium">Preview items</summary>
                    <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-base-content/70">
                        @foreach ($cleanup['entries'] as $entry)
                            <li>{{ $entry }}</li>
                        @endforeach
                    </ul>
                </details>
            @endif
            @can('manage-system')
                <form method="POST" action="{{ route('admin.settings.software.cleanup') }}" onsubmit="return confirm('Remove the old managed deployments shown in the preview?');">
                    @csrf
                    <input type="hidden" name="confirmation" value="1">
                    <button class="btn btn-outline" type="submit" @disabled(empty($cleanup['entries']))>Clean up</button>
                </form>
            @endcan
        </section>

        <section class="nexus-panel space-y-4 p-5" aria-labelledby="history-heading">
            <div>
                <h2 id="history-heading" class="text-lg font-semibold">Recent operations</h2>
                <p class="text-sm text-base-content/65">This history comes directly from the root-owned updater and remains available while Laravel restarts.</p>
            </div>
            <p class="hidden rounded-box bg-info/10 p-3 text-sm" data-operation-progress role="status"></p>
            <div class="overflow-x-auto">
                <table class="table table-zebra min-w-[700px]">
                    <thead><tr><th>Started</th><th>Operation</th><th>Component</th><th>Status</th><th>Stage</th></tr></thead>
                    <tbody>
                        @forelse ($operations as $operation)
                            <tr>
                                <td>{{ $operation['created_at'] ?? '—' }}</td>
                                <td>{{ str($operation['operation'] ?? 'unknown')->headline() }}</td>
                                <td>{{ $operation['component_id'] ?? '—' }}</td>
                                <td>{{ str($operation['status'] ?? 'unknown')->headline() }}</td>
                                <td>{{ str(data_get($operation, 'metadata.phase', 'unknown'))->headline() }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-base-content/65">No updater operations have been recorded.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <details class="nexus-panel p-5">
            <summary class="cursor-pointer font-semibold">Technical details</summary>
            <dl class="mt-4 grid gap-2 text-sm sm:grid-cols-2">
                <dt class="text-base-content/60">Updater version</dt><dd>{{ $updater['updater_version'] ?? 'Unknown' }}</dd>
                <dt class="text-base-content/60">Profile</dt><dd>{{ $updater['profile'] ?? 'Unknown' }}</dd>
                <dt class="text-base-content/60">Platform</dt><dd>{{ $updater['platform'] ?? 'Unknown' }}</dd>
                <dt class="text-base-content/60">Channel</dt><dd>{{ $updater['channel'] ?? 'stable' }}</dd>
            </dl>
        </details>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const page = document.querySelector('[data-software-page]');
            const operationUrl = page?.dataset.operationUrl;
            const progress = page?.querySelector('[data-operation-progress]');

            if (!operationUrl || !progress) {
                return;
            }

            progress.classList.remove('hidden');
            let delay = 1500;

            const poll = async () => {
                try {
                    const response = await fetch(operationUrl, { headers: { Accept: 'application/json' } });

                    if (!response.ok) {
                        throw new Error('Nexus is updating; reconnecting…');
                    }

                    const operation = await response.json();
                    const stage = operation.metadata?.phase || operation.status || 'working';
                    progress.textContent = `Nexus is ${String(stage).replaceAll('_', ' ')}.`;

                    if (['completed', 'failed', 'rolled_back', 'recovery_required'].includes(operation.status)) {
                        window.setTimeout(() => window.location.reload(), 1200);
                        return;
                    }

                    delay = 1500;
                } catch (error) {
                    progress.textContent = error.message || 'Nexus is updating; reconnecting…';
                    delay = Math.min(delay * 1.5, 10000);
                }

                window.setTimeout(poll, delay);
            };

            poll();
        });
    </script>
@endpush
