@php
    $stalePendingCount = collect($pendingRecoveryItems)->sum('stalePending');
@endphp

<div class="nexus-panel divide-y divide-base-300 overflow-hidden">
    <x-admin.settings-disclosure
        id="pending-request-recovery"
        title="Pending Request Recovery"
        description="Force genuinely stuck pending workflow rows into a terminal state without approving them."
        :status="$stalePendingCount > 0 ? number_format($stalePendingCount) . ' stale' : 'No stale rows'"
        :status-class="$stalePendingCount > 0 ? 'badge-warning' : 'badge-ghost'"
        class="bg-warning/5"
        :open="$errors->hasAny(['type', 'older_than_hours', 'confirm_release'])"
    >
        <div class="space-y-5">
            <x-contextual-help title="Use pending-request recovery only for confirmed stuck rows" owner="Operations and security" open>
                <x-slot:why>
                    A request can remain pending when a worker, external delivery, or earlier deployment stopped after creating the row. Recovery closes matching stale rows; it never approves or completes their business action.
                </x-slot:why>
                <x-slot:next>
                    First verify the domain queue and external system, choose the narrowest workflow, and use an age older than the normal processing window. Record the support context before releasing anything.
                </x-slot:next>
                <x-slot:timing>
                    Matching rows move immediately to that workflow's recovery terminal state. Related external transfers or messages are not retried by this control.
                </x-slot:timing>
                <x-slot:support>
                    If the external outcome is uncertain, do not release the row. Escalate with its request or correlation ID so the domain owner can reconcile it first.
                </x-slot:support>
            </x-contextual-help>

            <div class="overflow-x-auto rounded-box border border-base-300">
                <table class="table table-zebra" data-sortable="false">
                    <thead>
                    <tr>
                        <th scope="col">Workflow</th>
                        <th scope="col">Pending</th>
                        <th scope="col">Stale ({{ $stalePendingDefaultHours }}h+)</th>
                        <th scope="col">Oldest pending</th>
                        <th scope="col">Recovery action</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($pendingRecoveryItems as $item)
                        <tr>
                            <td class="font-semibold">{{ $item['label'] }}</td>
                            <td>{{ number_format($item['totalPending']) }}</td>
                            <td>
                                <span class="badge {{ $item['stalePending'] > 0 ? 'badge-warning' : 'badge-ghost' }}">
                                    {{ number_format($item['stalePending']) }}
                                </span>
                            </td>
                            <td>
                                @if ($item['oldestCreatedAt'])
                                    <div>{{ $item['oldestCreatedAt']->format('M d, Y H:i') }}</div>
                                    <div class="text-sm text-base-content/60">{{ $item['oldestCreatedAt']->diffForHumans() }}</div>
                                @else
                                    <span class="text-sm text-base-content/60">None</span>
                                @endif
                            </td>
                            <td>
                                <form
                                    method="POST"
                                    action="{{ route('admin.settings.pending-requests.release-stale') }}"
                                    class="grid min-w-64 gap-3"
                                    data-confirm="Release stale {{ strtolower($item['label']) }} rows? Matching requests will be moved to a terminal state."
                                    data-confirm-title="Release stale requests?"
                                    data-confirm-label="Release stale"
                                    data-confirm-tone="warning"
                                >
                                    @csrf
                                    <input type="hidden" name="type" value="{{ $item['type'] }}">

                                    <label class="block space-y-2">
                                        <span class="text-xs font-medium">Older than (hours)</span>
                                        <input
                                            type="number"
                                            class="input input-sm w-28"
                                            id="olderThanHours-{{ $item['type'] }}"
                                            name="older_than_hours"
                                            min="1"
                                            max="8760"
                                            value="{{ old('older_than_hours', $stalePendingDefaultHours) }}"
                                            required
                                        >
                                    </label>

                                    <label class="flex cursor-pointer items-start gap-2 text-xs leading-5">
                                        <input
                                            type="checkbox"
                                            class="checkbox checkbox-warning checkbox-sm mt-0.5"
                                            id="confirmRelease-{{ $item['type'] }}"
                                            name="confirm_release"
                                            value="1"
                                            required
                                        >
                                        <span>I reviewed the workflow and confirmed the external outcome is not uncertain.</span>
                                    </label>

                                    <button class="btn btn-warning btn-outline btn-sm justify-self-start" type="submit">Release stale</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </x-admin.settings-disclosure>
</div>
