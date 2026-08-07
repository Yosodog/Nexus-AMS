@extends('layouts.main')

@section('content')
    @php
        $allFindings = $violationsByPriority->flatten(1);
        $totalFindings = $allFindings->count();
        $highPriorityFindings = $violationsByPriority->get('high', collect())->count();
        $overdueFindings = $allFindings->filter(
            fn ($finding) => $finding->due_at !== null && $finding->due_at->isPast()
        )->count();
        $priorityColors = [
            'high' => 'error',
            'medium' => 'warning',
            'low' => 'info',
            'info' => 'neutral',
        ];
        $priorityMessages = [
            'high' => 'Needs immediate attention.',
            'medium' => 'Address soon to keep your nation in good standing.',
            'low' => 'Resolve when practical.',
            'info' => 'Review for awareness.',
        ];
        $groupLabels = [
            'power' => 'Power',
            'raw_resource' => 'Raw Resource',
            'manufacturing' => 'Manufacturing',
            'commerce_support' => 'Commerce & Support',
            'military' => 'Military',
        ];
        $eventLabels = [
            'opened' => 'Finding opened',
            'resolved' => 'Finding resolved',
            'acknowledged' => 'Acknowledged',
            'snoozed' => 'Reminders snoozed',
            'waived' => 'Finding waived',
            'admin_updated' => 'Remediation updated',
            'rule_revised' => 'Rule revised',
            'rule_disabled' => 'Rule disabled',
            'migration_disabled' => 'Rule needs rebuild',
            'removed_ineligible' => 'Finding closed',
        ];
        $formatEventDate = static function (mixed $value, bool $includeTime = false): ?string {
            if (! filled($value)) {
                return null;
            }

            try {
                $date = \Illuminate\Support\Carbon::parse($value);

                return $includeTime ? $date->toDayDateTimeString() : $date->toFormattedDateString();
            } catch (\Throwable) {
                return null;
            }
        };
        $eventDescriptions = static function ($event) use ($formatEventDate): string {
            $metadata = is_array($event->metadata) ? $event->metadata : [];
            $snoozedUntil = $formatEventDate($metadata['snoozed_until'] ?? null, true);
            $waivedUntil = $formatEventDate($metadata['waived_until'] ?? null);
            $dueAt = $formatEventDate($metadata['due_at'] ?? null);

            return match ($event->event_type) {
                'opened' => 'This issue was first detected.',
                'resolved' => 'The latest audit no longer found this issue.',
                'acknowledged' => ($metadata['note_present'] ?? false)
                    ? 'Acknowledged with a remediation note.'
                    : 'Acknowledged for follow-up.',
                'snoozed' => $snoozedUntil !== null
                    ? 'Reminders paused until '.$snoozedUntil.'.'
                    : 'Reminders were temporarily paused.',
                'waived' => $waivedUntil !== null
                    ? 'Waived until '.$waivedUntil.'.'
                    : 'An administrator waived this finding.',
                'admin_updated' => ($metadata['waiver_cleared'] ?? false)
                    ? 'The waiver was cleared and remediation details were updated.'
                    : ($dueAt !== null
                        ? 'The remediation due date is '.$dueAt.'.'
                        : 'Remediation details were updated.'),
                'rule_revised' => isset($metadata['previous_revision'], $metadata['new_revision'])
                    ? 'The rule changed from revision '.(int) $metadata['previous_revision'].' to '.(int) $metadata['new_revision'].'.'
                    : 'The rule logic changed and the prior finding was closed.',
                'rule_disabled' => 'The rule was disabled and its active finding was closed.',
                'migration_disabled' => 'The imported rule could not be converted and must be rebuilt by an administrator.',
                'removed_ineligible' => 'The finding was closed because the target is no longer eligible for alliance audits.',
                default => 'The status of this finding changed.',
            };
        };
    @endphp

    <div class="space-y-8">
        <section class="rounded-lg border border-base-300 bg-base-100 p-4 md:p-6" aria-labelledby="audit-health-title">
            <div class="flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between">
                <div class="min-w-0">
                    <p class="text-sm font-semibold text-base-content/70">Nation audit health</p>
                    <h1 id="audit-health-title" class="text-3xl font-bold leading-tight">{{ $nation->leader_name }}</h1>
                    <p class="mt-1 text-base text-base-content/70">{{ $nation->nation_name }}</p>
                </div>

                <dl class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <div>
                        <dt class="text-sm font-medium text-base-content/70">Active findings</dt>
                        <dd class="mt-1 text-2xl font-bold tabular-nums {{ $totalFindings > 0 ? 'text-warning' : 'text-success' }}">{{ $totalFindings }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-base-content/70">High priority</dt>
                        <dd class="mt-1 text-2xl font-bold tabular-nums {{ $highPriorityFindings > 0 ? 'text-error' : '' }}">{{ $highPriorityFindings }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-base-content/70">Overdue</dt>
                        <dd class="mt-1 text-2xl font-bold tabular-nums {{ $overdueFindings > 0 ? 'text-error' : '' }}">{{ $overdueFindings }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-base-content/70">Nation</dt>
                        <dd class="mt-1 text-sm font-semibold tabular-nums">{{ number_format($nation->score, 2) }} score · {{ $nation->num_cities }} cities</dd>
                    </div>
                </dl>
            </div>
        </section>

        <section class="space-y-6" aria-labelledby="active-findings-title">
            <div>
                <h2 id="active-findings-title" class="text-2xl font-bold">Active findings</h2>
                <p class="mt-1 max-w-prose text-base text-base-content/70">
                    Review what matched, what the audit expected, and the recommended next step.
                </p>
            </div>

            @if($totalFindings === 0)
                <div class="rounded-lg border border-success/40 bg-success/10 p-6" role="status">
                    <h3 class="text-xl font-semibold text-success">All clear</h3>
                    <p class="mt-2 max-w-prose text-base">
                        No active audit findings were found for your nation or cities. Your report will update after the next audit run.
                    </p>
                </div>
            @else
                @foreach($priorityOrder as $priority)
                    @php
                        $items = $violationsByPriority->get($priority->value, collect());
                        $color = $priorityColors[$priority->value] ?? 'neutral';
                    @endphp

                    @if($items->isNotEmpty())
                        <section class="space-y-4" aria-labelledby="priority-{{ $priority->value }}">
                            <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                                <div>
                                    <div class="flex items-center gap-2">
                                        <span class="badge badge-{{ $color }}">{{ ucfirst($priority->value) }}</span>
                                        <h3 id="priority-{{ $priority->value }}" class="text-xl font-semibold">
                                            {{ $items->count() }} {{ \Illuminate\Support\Str::plural('finding', $items->count()) }}
                                        </h3>
                                    </div>
                                    <p class="mt-1 text-base text-base-content/70">{{ $priorityMessages[$priority->value] ?? 'Review this finding.' }}</p>
                                </div>
                            </div>

                            <div class="space-y-4">
                                @foreach($items as $violation)
                                    @php
                                        $details = is_array($violation->details) ? $violation->details : [];
                                        $safeEvidence = collect(is_array($details['evidence'] ?? null) ? $details['evidence'] : [])
                                            ->filter(fn ($item) => is_array($item) && ($item['member_safe'] ?? false) === true)
                                            ->values();
                                        $primaryEvidenceIndex = $safeEvidence->search(
                                            fn ($item) => ($item['scope'] ?? 'criteria') === 'criteria' && ($item['matched'] ?? false) === true
                                        );
                                        $primaryEvidenceIndex = $primaryEvidenceIndex === false ? 0 : $primaryEvidenceIndex;
                                        $primaryEvidence = $safeEvidence->get($primaryEvidenceIndex);
                                        $additionalEvidence = $safeEvidence->reject(
                                            fn ($item, $index) => $index === $primaryEvidenceIndex
                                        )->values();
                                        $isOverdue = $violation->due_at !== null && $violation->due_at->isPast();
                                        $targetLabel = optional($violation->rule?->target_type)->value === 'city'
                                            ? ($violation->city?->name ?? 'City')
                                            : ($nation->nation_name ?? 'Nation-wide');
                                    @endphp

                                    <article class="rounded-lg border {{ $isOverdue || $priority->value === 'high' ? 'border-error/50 bg-error/5' : 'border-base-300 bg-base-100' }} p-4 md:p-6">
                                        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                            <div class="min-w-0">
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <span class="badge badge-{{ $color }}">{{ ucfirst($priority->value) }}</span>
                                                    <span class="badge badge-outline">{{ $targetLabel }}</span>
                                                    @if($isOverdue)
                                                        <span class="badge badge-error">Overdue</span>
                                                    @endif
                                                </div>
                                                <h4 class="mt-3 text-xl font-semibold">{{ $violation->rule?->name ?? 'Audit finding' }}</h4>
                                                <p class="mt-2 max-w-prose text-base leading-relaxed">
                                                    {{ $violation->rule?->description ?? 'This audit check needs your attention.' }}
                                                </p>
                                            </div>
                                        </div>

                                        <div class="mt-6 space-y-4">
                                            <div>
                                                <h5 class="text-sm font-semibold">Why this matched</h5>
                                                @if(is_array($primaryEvidence))
                                                    <p class="mt-2 max-w-prose text-base leading-relaxed">
                                                        {{ $primaryEvidence['condition'] ?? 'The current value matched this audit condition.' }}
                                                    </p>
                                                    <dl class="mt-4 grid gap-4 rounded-lg bg-base-200/60 p-4 sm:grid-cols-2">
                                                        <div>
                                                            <dt class="text-sm font-medium text-base-content/70">Observed</dt>
                                                            <dd class="mt-1 text-base font-semibold tabular-nums">{{ $primaryEvidence['observed_display'] ?? 'Unavailable' }}</dd>
                                                        </div>
                                                        <div>
                                                            <dt class="text-sm font-medium text-base-content/70">Expected</dt>
                                                            <dd class="mt-1 text-base font-semibold tabular-nums">{{ $primaryEvidence['expected_display'] ?? 'No expected value provided' }}</dd>
                                                        </div>
                                                    </dl>
                                                @elseif(filled($details['summary'] ?? null))
                                                    <p class="mt-2 max-w-prose text-base leading-relaxed">{{ $details['summary'] }}</p>
                                                @else
                                                    <p class="mt-2 max-w-prose text-base text-base-content/70">
                                                        Detailed evidence will appear after this finding is evaluated again.
                                                    </p>
                                                @endif
                                            </div>

                                            <div>
                                                <h5 class="text-sm font-semibold">How to resolve it</h5>
                                                <p class="mt-2 max-w-prose text-base leading-relaxed">
                                                    {{ $violation->rule?->remediation_guidance ?? 'Review the issue above and update the affected nation or city value. If you need help, contact an alliance administrator.' }}
                                                </p>
                                            </div>

                                            <x-contextual-help title="Understanding this audit finding" owner="Audit policy">
                                                <x-slot:why>
                                                    This finding records the latest observed value that matched the rule shown above. It is a policy signal, not proof of intent or misconduct.
                                                </x-slot:why>
                                                <x-slot:next>
                                                    Follow the remediation guidance, then acknowledge the finding with a concise note if requested. Do not waive or dismiss it merely to remove it from the list.
                                                </x-slot:next>
                                                <x-slot:timing>
                                                    The finding is reevaluated on the next successful audit after the underlying Politics &amp; War or Nexus data refreshes.
                                                </x-slot:timing>
                                                <x-slot:support>
                                                    Contact the audit policy owner with the rule name and observed value if the evidence remains incorrect after a fresh audit.
                                                </x-slot:support>
                                            </x-contextual-help>

                                            @if($additionalEvidence->isNotEmpty())
                                                <details class="group border-t border-base-300 pt-4">
                                                    <summary class="cursor-pointer text-sm font-semibold focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary">
                                                        View {{ $additionalEvidence->count() }} more {{ \Illuminate\Support\Str::plural('condition', $additionalEvidence->count()) }}
                                                    </summary>
                                                    <div class="mt-4 divide-y divide-base-300">
                                                        @foreach($additionalEvidence as $evidence)
                                                            <div class="py-4 first:pt-0 last:pb-0">
                                                                <p class="text-base">{{ $evidence['condition'] ?? 'Additional audit condition' }}</p>
                                                                <dl class="mt-3 grid gap-4 text-sm sm:grid-cols-2">
                                                                    <div>
                                                                        <dt class="font-medium text-base-content/70">Observed</dt>
                                                                        <dd class="mt-1 font-semibold tabular-nums">{{ $evidence['observed_display'] ?? 'Unavailable' }}</dd>
                                                                    </div>
                                                                    <div>
                                                                        <dt class="font-medium text-base-content/70">Expected</dt>
                                                                        <dd class="mt-1 font-semibold tabular-nums">{{ $evidence['expected_display'] ?? 'No expected value provided' }}</dd>
                                                                    </div>
                                                                </dl>
                                                            </div>
                                                        @endforeach
                                                    </div>
                                                </details>
                                            @endif

                                            <div class="border-t border-base-300 pt-4">
                                                <div class="flex flex-wrap gap-2" aria-label="Finding status">
                                                    @if($violation->acknowledged_at)
                                                        <span class="badge badge-success">Acknowledged</span>
                                                    @endif
                                                    @if($violation->isSnoozed())
                                                        <span class="badge badge-info">Reminders snoozed</span>
                                                    @endif
                                                    @if($violation->isWaived())
                                                        <span class="badge badge-warning">Waived</span>
                                                    @endif
                                                    @if($violation->due_at)
                                                        <span class="badge {{ $isOverdue ? 'badge-error' : 'badge-outline' }}">Due {{ $violation->due_at->toFormattedDateString() }}</span>
                                                    @endif
                                                </div>

                                                <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-2">
                                                    <div>
                                                        <dt class="font-medium text-base-content/70">First detected</dt>
                                                        <dd class="mt-1 tabular-nums">
                                                            <time datetime="{{ $violation->first_detected_at?->toIso8601String() }}">
                                                                {{ $violation->first_detected_at?->toDayDateTimeString() ?? 'Unknown' }}
                                                            </time>
                                                        </dd>
                                                    </div>
                                                    <div>
                                                        <dt class="font-medium text-base-content/70">Last checked</dt>
                                                        <dd class="mt-1 tabular-nums">
                                                            <time datetime="{{ $violation->last_evaluated_at?->toIso8601String() }}">
                                                                {{ $violation->last_evaluated_at?->toDayDateTimeString() ?? 'Not checked yet' }}
                                                            </time>
                                                        </dd>
                                                    </div>
                                                    @if($violation->isSnoozed())
                                                        <div>
                                                            <dt class="font-medium text-base-content/70">Reminder status</dt>
                                                            <dd class="mt-1">Paused until {{ $violation->snoozed_until->toDayDateTimeString() }}</dd>
                                                        </div>
                                                    @endif
                                                    @if($violation->isWaived())
                                                        <div>
                                                            <dt class="font-medium text-base-content/70">Waiver status</dt>
                                                            <dd class="mt-1">Waived until {{ $violation->waived_until->toFormattedDateString() }}</dd>
                                                        </div>
                                                    @endif
                                                </dl>

                                                @if($violation->remediation_note)
                                                    <div class="mt-4 rounded-lg bg-base-200/60 p-4">
                                                        <p class="text-sm font-semibold">Remediation note</p>
                                                        <p class="mt-2 text-base">{{ $violation->remediation_note }}</p>
                                                    </div>
                                                @endif
                                            </div>

                                            <div class="grid gap-4 border-t border-base-300 pt-4 lg:grid-cols-2">
                                                <form method="POST" action="{{ route('audit.results.acknowledge', $violation) }}" class="audit-finding-action-form grid gap-4">
                                                    @csrf
                                                    <label class="sr-only" for="acknowledge-note-{{ $violation->id }}">Optional remediation note</label>
                                                    <input
                                                        id="acknowledge-note-{{ $violation->id }}"
                                                        class="input w-full"
                                                        name="note"
                                                        maxlength="500"
                                                        placeholder="Optional remediation note"
                                                    >
                                                    <button class="btn btn-primary w-full sm:w-auto" type="submit">Acknowledge</button>
                                                </form>
                                                <form method="POST" action="{{ route('audit.results.snooze', $violation) }}" class="audit-finding-action-form grid gap-4">
                                                    @csrf
                                                    <label class="sr-only" for="snooze-hours-{{ $violation->id }}">Snooze audit reminders</label>
                                                    <select id="snooze-hours-{{ $violation->id }}" class="select w-full" name="hours">
                                                        <option value="24">Snooze for 1 day</option>
                                                        <option value="72">Snooze for 3 days</option>
                                                        <option value="168">Snooze for 7 days</option>
                                                    </select>
                                                    <button class="btn btn-outline w-full sm:w-auto" type="submit">Snooze</button>
                                                </form>
                                            </div>
                                        </div>
                                    </article>
                                @endforeach
                            </div>
                        </section>
                    @endif
                @endforeach
            @endif
        </section>

        <section class="rounded-lg border border-base-300 bg-base-100" aria-labelledby="remediation-history-title">
            <div class="border-b border-base-300 p-4 md:p-6">
                <h2 id="remediation-history-title" class="text-2xl font-bold">Recent remediation history</h2>
                <p class="mt-1 max-w-prose text-base text-base-content/70">A concise record of recent finding and remediation changes.</p>
            </div>
            <div class="divide-y divide-base-300">
                @forelse($remediationHistory as $event)
                    @php
                        $metadata = is_array($event->metadata) ? $event->metadata : [];
                        $snapshot = is_array($metadata['rule_snapshot'] ?? null) ? $metadata['rule_snapshot'] : [];
                        $eventRuleName = $snapshot['name'] ?? $event->rule?->name ?? 'Archived audit rule';
                        $eventPriority = $snapshot['priority'] ?? $event->rule?->priority?->value;
                        $eventSummary = $snapshot['summary'] ?? null;
                        $eventRevision = $snapshot['revision'] ?? null;
                    @endphp

                    <div class="flex flex-col gap-4 p-4 md:p-6 lg:flex-row lg:items-start lg:justify-between">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-semibold">{{ $eventRuleName }}</span>
                                <span class="badge badge-ghost">{{ $eventLabels[$event->event_type] ?? 'Audit update' }}</span>
                                @if(filled($eventPriority))
                                    <span class="badge badge-outline">{{ ucfirst($eventPriority) }}</span>
                                @endif
                                @if($eventRevision !== null)
                                    <span class="badge badge-outline">Revision {{ (int) $eventRevision }}</span>
                                @endif
                                @if($event->city)
                                    <span class="badge badge-outline">{{ $event->city->name }}</span>
                                @endif
                            </div>
                            <p class="mt-2 text-base">{{ $eventDescriptions($event) }}</p>
                            @if(filled($eventSummary))
                                <p class="mt-2 max-w-prose text-sm text-base-content/70">{{ $eventSummary }}</p>
                            @endif
                        </div>
                        <time class="shrink-0 text-sm text-base-content/70 tabular-nums" datetime="{{ $event->occurred_at->toIso8601String() }}">
                            {{ $event->occurred_at->toDayDateTimeString() }}
                        </time>
                    </div>
                @empty
                    <p class="p-6 text-base text-base-content/70">No remediation history yet.</p>
                @endforelse
            </div>
        </section>

        <details class="rounded-lg border border-base-300 bg-base-100">
            <summary class="cursor-pointer p-4 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary md:p-6">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="text-sm font-semibold text-base-content/70">Build recommendation</p>
                        <h2 class="text-2xl font-bold">Alliance city build</h2>
                        <p class="mt-1 max-w-prose text-base text-base-content/70">
                            Open profitability, improvements, and bulk-import JSON.
                        </p>
                    </div>
                    <span class="badge badge-outline">{{ $buildRecommendation ? 'Recommendation ready' : ($buildRecommendationPending ? 'Recalculating' : 'Not generated') }}</span>
                </div>
            </summary>

            <div class="space-y-6 border-t border-base-300 p-4 md:p-6">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <p class="max-w-prose text-base text-base-content/70">
                        One build evaluated across your real city profiles at the highest recovered city capacity, while preserving your nation's MMR floor.
                    </p>
                    <div class="flex flex-col gap-4 sm:flex-row">
                        <form method="POST" action="{{ route('audit.recommendation.regenerate') }}">
                            @csrf
                            <button type="submit" class="btn btn-outline btn-primary w-full sm:w-auto">Regenerate build</button>
                        </form>

                        @if($buildRecommendation)
                            <button type="button" class="btn btn-outline w-full sm:w-auto" data-copy-build="{{ $buildRecommendationJson }}">Copy JSON</button>
                            <a
                                href="https://politicsandwar.com/city/improvements/bulk-import/"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="btn btn-primary w-full sm:w-auto"
                            >
                                Open bulk import
                            </a>
                        @endif
                    </div>
                </div>

                @if($buildRecommendation)
                    @if(data_get($buildRecommendation->calculation_context, 'market.stale'))
                        <div class="alert alert-warning">
                            Market pricing is stale. The build uses the latest usable snapshot and will refresh automatically.
                        </div>
                    @endif

                    @if(filled(data_get($buildRecommendation->calculation_context, 'market.fallback_resources')))
                        <div class="alert alert-warning">
                            Aggregate fallback pricing was used for:
                            {{ implode(', ', data_get($buildRecommendation->calculation_context, 'market.fallback_resources', [])) }}.
                        </div>
                    @endif

                    @if($buildRecommendation->cities_below_target > 0)
                        <div class="alert alert-info">
                            {{ $buildRecommendation->cities_below_target }} {{ \Illuminate\Support\Str::plural('city', $buildRecommendation->cities_below_target) }}
                            require {{ number_format($buildRecommendation->infrastructure_shortfall, 2) }} total infrastructure to run this build everywhere.
                        </div>
                    @endif

                    <dl class="grid overflow-hidden rounded-lg border border-base-300 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-7">
                        <div class="border-b border-base-300 p-4 sm:border-r lg:border-b-0">
                            <dt class="text-sm font-medium text-base-content/70">Profit / day</dt>
                            <dd class="mt-2 text-2xl font-bold tabular-nums {{ $buildRecommendation->converted_profit_per_day >= 0 ? 'text-success' : 'text-error' }}">
                                ${{ number_format($buildRecommendation->converted_profit_per_day, 2) }}
                            </dd>
                            <p class="mt-1 text-sm text-base-content/70">Per city</p>
                        </div>
                        <div class="border-b border-base-300 p-4 lg:border-b-0 lg:border-r">
                            <dt class="text-sm font-medium text-base-content/70">Money / day</dt>
                            <dd class="mt-2 text-2xl font-bold tabular-nums {{ $buildRecommendation->money_profit_per_day >= 0 ? 'text-success' : 'text-error' }}">
                                ${{ number_format($buildRecommendation->money_profit_per_day, 2) }}
                            </dd>
                        </div>
                        <div class="border-b border-base-300 p-4 sm:border-r lg:border-b-0">
                            <dt class="text-sm font-medium text-base-content/70">Disease</dt>
                            <dd class="mt-2 text-2xl font-bold tabular-nums">{{ number_format($buildRecommendation->disease, 2) }}</dd>
                        </div>
                        <div class="border-b border-base-300 p-4 lg:border-b-0 lg:border-r">
                            <dt class="text-sm font-medium text-base-content/70">Pollution</dt>
                            <dd class="mt-2 text-2xl font-bold tabular-nums">{{ number_format($buildRecommendation->pollution) }}</dd>
                        </div>
                        <div class="border-b border-base-300 p-4 sm:border-r sm:border-b-0">
                            <dt class="text-sm font-medium text-base-content/70">Crime</dt>
                            <dd class="mt-2 text-2xl font-bold tabular-nums">{{ number_format($buildRecommendation->crime, 2) }}</dd>
                        </div>
                        <div class="border-b border-base-300 p-4 sm:border-b-0 lg:border-r">
                            <dt class="text-sm font-medium text-base-content/70">Commerce</dt>
                            <dd class="mt-2 text-2xl font-bold tabular-nums">{{ number_format($buildRecommendation->commerce) }}</dd>
                        </div>
                        <div class="p-4 sm:border-r lg:border-r-0">
                            <dt class="text-sm font-medium text-base-content/70">Population</dt>
                            <dd class="mt-2 text-2xl font-bold tabular-nums">{{ number_format($buildRecommendation->population) }}</dd>
                        </div>
                    </dl>

                    <div class="flex flex-wrap gap-2 text-sm">
                        <span class="badge badge-outline">Infra {{ number_format($buildRecommendation->infra_needed) }}</span>
                        <span class="badge badge-outline">Land {{ number_format($buildRecommendation->land_used, 2) }}</span>
                        <span class="badge badge-outline">{{ $buildRecommendation->imp_total }} / {{ $buildRecommendation->available_slots }} slots</span>
                        <span class="badge badge-outline">Highest recovered city target</span>
                        <span class="badge badge-outline">{{ $buildRecommendation->price_basis }}</span>
                        @if(filled(data_get($buildRecommendation->calculation_context, 'market.calculated_at')))
                            <span class="badge badge-outline">
                                Prices {{ \Illuminate\Support\Carbon::parse(data_get($buildRecommendation->calculation_context, 'market.calculated_at'))->toDayDateTimeString() }}
                            </span>
                        @endif
                        <span class="badge badge-ghost">Updated {{ $buildRecommendation->calculated_at?->diffForHumans() }}</span>
                    </div>

                    <div class="grid gap-6 lg:grid-cols-2">
                        @foreach($buildRecommendationGroups as $group => $items)
                            <section class="border-t border-base-300 pt-4">
                                <h3 class="text-xl font-semibold">{{ $groupLabels[$group] ?? ucfirst(str_replace('_', ' ', $group)) }}</h3>
                                @if(empty($items))
                                    <p class="mt-2 text-base text-base-content/70">None</p>
                                @else
                                    <div class="mt-4 flex flex-wrap gap-2">
                                        @foreach($items as $item)
                                            <span class="badge badge-outline">{{ $item['label'] }} × {{ $item['count'] }}</span>
                                        @endforeach
                                    </div>
                                @endif
                            </section>
                        @endforeach
                    </div>

                    <details class="border-t border-base-300 pt-4">
                        <summary class="cursor-pointer text-base font-semibold focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary">
                            View build JSON
                        </summary>
                        <div class="mt-4">
                            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                                <p class="text-base text-base-content/70">Ready for Politics & War bulk import.</p>
                                <button type="button" class="btn btn-outline w-full sm:w-auto" data-copy-build="{{ $buildRecommendationJson }}">Copy JSON</button>
                            </div>
                            <textarea class="textarea mt-4 h-80 w-full font-mono text-sm" readonly aria-label="Build recommendation JSON">{{ $buildRecommendationJson }}</textarea>
                        </div>
                    </details>
                @else
                    <div class="rounded-lg border border-dashed border-base-300 bg-base-200/40 p-6">
                        <h3 class="text-xl font-semibold">{{ $buildRecommendationPending ? 'Build recommendation is updating' : 'No build recommendation yet' }}</h3>
                        <p class="mt-2 max-w-prose text-base text-base-content/70">
                            @if($buildRecommendationPending)
                                The current calculation is pending. Your older recommendation stays hidden so it cannot be mistaken for current data.
                            @else
                                Generate one to see the recommended JSON, profitability, city statistics, and quick import actions.
                            @endif
                        </p>
                    </div>
                @endif
            </div>
        </details>
    </div>

    <script>
        document.querySelectorAll('[data-copy-build]').forEach((button) => {
            button.addEventListener('click', async () => {
                const payload = button.getAttribute('data-copy-build') || '';
                const originalText = button.textContent;

                try {
                    if (navigator.clipboard?.writeText && window.isSecureContext) {
                        await navigator.clipboard.writeText(payload);
                    } else {
                        const input = document.createElement('textarea');
                        input.value = payload;
                        input.setAttribute('readonly', '');
                        input.style.position = 'absolute';
                        input.style.left = '-9999px';
                        document.body.appendChild(input);
                        input.select();
                        document.execCommand('copy');
                        document.body.removeChild(input);
                    }

                    button.textContent = 'Copied';
                } catch (error) {
                    console.error('Could not copy build JSON', error);
                    button.textContent = 'Copy failed';
                }

                window.setTimeout(() => {
                    button.textContent = originalText;
                }, 1500);
            });
        });
    </script>
@endsection
