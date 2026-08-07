@if ($requests->isEmpty())
    <p class="nexus-text-muted text-sm">No recent grant requests.</p>
@else
    <div class="overflow-x-auto">
    <table class="table table-sm table-zebra" data-sortable="false">
        <thead>
            <tr class="nexus-text-muted">
                <th>Program</th>
                <th>Status</th>
                <th>Decision</th>
                <th>Timeline</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($requests as $request)
                @php
                    $statusPresentation = match ($request->status) {
                        'approved' => ['intent' => 'success', 'icon' => 'check-circle', 'label' => 'Approved'],
                        'denied' => ['intent' => 'failure', 'icon' => 'x-circle', 'label' => 'Denied'],
                        default => ['intent' => 'pending', 'icon' => 'clock', 'label' => 'Pending'],
                    };
                @endphp
                <tr>
                    <td>
                        <div>{{ $request->program_name_snapshot ?? 'Not recorded' }}</div>
                        <div class="text-xs nexus-text-muted">Version {{ $request->program_version_snapshot ?? 'Not recorded' }}</div>
                    </td>
                    <td>
                        <x-nexus-status
                            :intent="$statusPresentation['intent']"
                            :icon="$statusPresentation['icon']"
                            :label="$statusPresentation['label']"
                        />
                    </td>
                    <td>
                        @if($request->status === 'pending')
                            <span class="text-sm nexus-text-muted">Awaiting decision</span>
                        @else
                            <div class="text-sm font-medium">{{ $request->decision_reason_code?->label() ?? 'Not recorded' }}</div>
                            <div class="mt-1 max-w-sm whitespace-pre-line text-xs nexus-text-muted">{{ $request->memberDecisionExplanation() ?? 'Not recorded' }}</div>
                            @if(($canManageGrants ?? false) && filled($request->decision_internal_note ?? null))
                                <details class="mt-1">
                                    <summary class="cursor-pointer text-xs font-medium">Staff-only note</summary>
                                    <p class="mt-1 max-w-sm whitespace-pre-line text-xs nexus-text-muted">{{ $request->decision_internal_note }}</p>
                                </details>
                            @endif
                        @endif
                    </td>
                    <td class="text-xs">
                        <div>Submitted <x-time.display :value="$request->submittedAtForHistory()" label="Submitted" /></div>
                        <div>
                            Decided
                            @if($request->decidedAtForHistory())
                                <x-time.display :value="$request->decidedAtForHistory()" label="Decided" />
                            @else
                                {{ $request->status === 'pending' ? 'Pending' : 'Not recorded' }}
                            @endif
                        </div>
                        <div>
                            Disbursed
                            @if($request->disbursed_at)
                                <x-time.display :value="$request->disbursed_at" label="Disbursed" />
                            @else
                                {{ $request->status === 'approved' ? 'Not recorded' : 'Not applicable' }}
                            @endif
                        </div>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
    </div>
@endif
