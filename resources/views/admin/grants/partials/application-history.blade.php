@php
    use App\Models\GrantApplication;
    use Illuminate\Support\Str;
@endphp

<section class="nexus-panel" aria-labelledby="grant-application-history-title">
    <div class="nexus-panel__header">
        <div>
            <h2 id="grant-application-history-title" class="nexus-section-title">Application history</h2>
            <p class="nexus-body-muted mt-1">Latest submissions and decisions, newest first. Legacy fields are left explicitly unrecorded.</p>
        </div>
        <span class="text-sm tabular-nums nexus-text-muted">
            Showing {{ number_format($recentApplications->firstItem() ?? 0) }}–{{ number_format($recentApplications->lastItem() ?? 0) }}
            of {{ number_format($recentApplications->total()) }}
        </span>
    </div>

    <div class="overflow-x-auto">
        <table class="nexus-table" data-sortable="false">
            <thead>
                <tr>
                    <th>Program / member</th>
                    <th>Status / reason</th>
                    <th>Recorded payout</th>
                    <th>Timeline</th>
                    <th>Reviewer</th>
                </tr>
            </thead>
            <tbody>
                @forelse($recentApplications as $application)
                    @php
                        $submittedAt = $application->submittedAtForHistory();
                        $decidedAt = $application->decidedAtForHistory();
                        $payout = collect(GrantApplication::PAYOUT_COLUMNS)
                            ->mapWithKeys(fn (string $resource) => [$resource => (float) ($application->{$resource} ?? 0)])
                            ->filter(fn (float $amount) => $amount > 0);
                        $statusPresentation = match ($application->status) {
                            'approved' => ['intent' => 'success', 'icon' => 'check-circle', 'label' => 'Approved'],
                            'denied' => ['intent' => 'failure', 'icon' => 'x-circle', 'label' => 'Denied'],
                            default => ['intent' => 'pending', 'icon' => 'clock', 'label' => 'Pending'],
                        };
                    @endphp
                    <tr>
                        <td>
                            <div class="font-semibold">{{ $application->program_name_snapshot ?? 'Not recorded' }}</div>
                            <div class="text-sm nexus-text-muted">
                                Version {{ $application->program_version_snapshot ?? 'Not recorded' }}
                                · Request #{{ $application->id }}
                            </div>
                            <x-copy-action :value="(string) $application->id" label="grant request ID" class="mt-2" />
                            <div class="mt-1 text-sm">
                                {{ $application->nation?->leader_name ?? ('Nation #'.$application->nation_id) }}
                            </div>
                        </td>
                        <td>
                            <x-nexus-status
                                :intent="$statusPresentation['intent']"
                                :icon="$statusPresentation['icon']"
                                :label="$statusPresentation['label']"
                            />
                            @if($application->status !== 'pending')
                                <div class="mt-2 text-sm font-medium">{{ $application->decision_reason_code?->label() ?? 'Not recorded' }}</div>
                                <div class="mt-1 max-w-md whitespace-pre-line text-sm nexus-text-muted">
                                    {{ $application->memberDecisionExplanation() ?? 'Not recorded' }}
                                </div>
                                @if($canManageGrants && filled($application->decision_internal_note ?? null))
                                    <details class="mt-2">
                                        <summary class="cursor-pointer text-sm font-medium">Staff-only note</summary>
                                        <p class="mt-1 max-w-md whitespace-pre-line text-sm nexus-text-muted">{{ $application->decision_internal_note }}</p>
                                    </details>
                                @endif
                            @endif
                        </td>
                        <td>
                            <div class="flex max-w-sm flex-wrap gap-1">
                                @forelse($payout as $resource => $amount)
                                    <span class="nexus-status nexus-status--neutral">
                                        {{ Str::headline($resource) }} {{ $resource === 'money' ? '$'.number_format($amount, 0) : number_format($amount, 0) }}
                                    </span>
                                @empty
                                    <span class="text-sm nexus-text-muted">{{ $application->hasProgramSnapshot() ? 'No payout configured' : 'Not recorded' }}</span>
                                @endforelse
                            </div>
                            <div class="mt-1 text-xs nexus-text-muted">Account {{ $application->account?->name ?? '#'.$application->account_id }}</div>
                        </td>
                        <td class="text-sm">
                            <div>Submitted: <x-time.display :value="$submittedAt" label="Submitted" /></div>
                            <div>
                                Decided:
                                @if($decidedAt)
                                    <x-time.display :value="$decidedAt" label="Decided" />
                                @else
                                    {{ $application->status === 'pending' ? 'Pending' : 'Not recorded' }}
                                @endif
                            </div>
                            <div>
                                Disbursed:
                                @if($application->disbursed_at)
                                    <x-time.display :value="$application->disbursed_at" label="Disbursed" />
                                @else
                                    {{ $application->status === 'approved' ? 'Not recorded' : 'Not applicable' }}
                                @endif
                            </div>
                        </td>
                        <td class="text-sm">
                            {{ $application->reviewer?->name ?? ($application->reviewed_by_user_id ? 'Former staff user' : 'Not recorded') }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="py-8 text-center nexus-text-muted">No standard-grant applications have been recorded.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="border-t border-base-300 px-5 py-4">
        {{ $recentApplications->links() }}
    </div>
</section>
