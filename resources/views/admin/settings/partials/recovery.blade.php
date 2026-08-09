@php
    $stalePendingCount = collect($pendingRecoveryItems)->sum('stalePending');
@endphp

<div class="nexus-panel divide-y divide-base-300 overflow-hidden">
    <x-admin.settings-disclosure
        id="pending-request-recovery"
        title="Stuck request recovery"
        description="Close requests that are stuck in a pending state without approving them."
        :status="$stalePendingCount > 0 ? number_format($stalePendingCount) . ' stuck' : 'No stuck requests'"
        :status-class="$stalePendingCount > 0 ? 'badge-warning' : 'badge-ghost'"
        class="bg-warning/5"
        :open="$errors->hasAny(['type', 'older_than_hours', 'confirm_release'])"
    >
        <div class="space-y-5">
            <x-contextual-help title="Use recovery only after confirming a request is stuck" owner="Operations and security" open>
                <x-slot:why>
                    A request may stay pending if processing or delivery stops unexpectedly. Recovery closes old pending requests. It does not approve them.
                </x-slot:why>
                <x-slot:next>
                    Check the request in its normal area and confirm any related transfer or message. Choose the specific request type and an age longer than its normal processing time. Record why you are releasing it.
                </x-slot:next>
                <x-slot:timing>
                    Matching requests close immediately. This tool does not retry transfers or messages.
                </x-slot:timing>
                <x-slot:support>
                    If you cannot confirm what happened, do not release the request. Escalate with the request ID so the responsible team can investigate.
                </x-slot:support>
            </x-contextual-help>

            <div class="overflow-x-auto rounded-box border border-base-300">
                <table class="table table-zebra" data-sortable="false">
                    <thead>
                    <tr>
                        <th scope="col">Request type</th>
                        <th scope="col">Pending</th>
                        <th scope="col">Stuck for more than {{ $stalePendingDefaultHours }} hours</th>
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
                                    <div class="text-sm text-base-content/70">{{ $item['oldestCreatedAt']->diffForHumans() }}</div>
                                @else
                                    <span class="text-sm text-base-content/70">None</span>
                                @endif
                            </td>
                            <td>
                                <form
                                    method="POST"
                                    action="{{ route('admin.settings.pending-requests.release-stale') }}"
                                    class="grid min-w-64 gap-3"
                                    data-confirm="Close stuck {{ strtolower($item['label']) }} requests? This does not approve them or retry related transfers or messages."
                                    data-confirm-title="Close stuck requests?"
                                    data-confirm-label="Close requests"
                                    data-confirm-tone="warning"
                                >
                                    @csrf
                                    <input type="hidden" name="type" value="{{ $item['type'] }}">

                                    <label class="block space-y-2">
                                        <span class="text-sm font-medium">Older than (hours)</span>
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
                                        <span>I reviewed these requests and confirmed that any related transfer or message has a known result.</span>
                                    </label>

                                    <button class="btn btn-warning btn-outline btn-sm justify-self-start" type="submit">Close stuck requests</button>
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
