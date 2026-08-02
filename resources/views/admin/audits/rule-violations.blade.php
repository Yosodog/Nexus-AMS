@extends('layouts.admin')

@section('title', 'Audit Findings')

@section('content')
    @php
        $priorityBadge = match ($rule->priority->value) {
            'high' => 'badge-error',
            'medium' => 'badge-warning',
            'low' => 'badge-info',
            default => 'badge-ghost',
        };
        $evidenceFor = static fn ($finding) => collect(
            is_array(data_get($finding->details, 'evidence')) ? data_get($finding->details, 'evidence') : []
        )->filter(fn ($item) => is_array($item))->values();
        $targetName = static fn ($finding) => $finding->target_type->value === 'city'
            ? ($finding->city?->name ?? 'Unknown city')
            : ($finding->nation?->nation_name ?? 'Unknown nation');
    @endphp

    <x-header :title="'Findings: ' . $rule->name" separator use-h1>
        <x-slot:subtitle>{{ $plainLanguageSummary }}</x-slot:subtitle>
        <x-slot:actions>
            <div class="flex flex-wrap gap-2">
                @can('manage-audits')
                    <a href="{{ route('admin.audits.rules.edit', $rule) }}" class="btn btn-outline btn-primary">
                        <x-icon name="o-pencil" class="size-5" aria-hidden="true" />
                        Edit rule
                    </a>
                @endcan
                <a href="{{ route('admin.audits.rules.index') }}" class="btn btn-ghost">Back to rules</a>
            </div>
        </x-slot:actions>
    </x-header>

    <section class="nexus-panel" aria-labelledby="finding-list-heading">
        <div class="nexus-panel__header">
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <h2 id="finding-list-heading" class="nexus-section-title">Current findings</h2>
                    <span class="badge {{ $priorityBadge }}">{{ ucfirst($rule->priority->value) }}</span>
                    <span class="badge badge-outline">{{ ucfirst($rule->target_type->value) }}</span>
                </div>
                <p class="mt-1 text-sm text-base-content/60">{{ number_format($violations->count()) }} matching {{ \Illuminate\Support\Str::plural('target', $violations->count()) }}</p>
            </div>
        </div>

        <form method="GET" action="{{ route('admin.audits.rules.violations', $rule) }}" class="grid gap-3 border-b border-base-300 p-4 md:grid-cols-2 lg:grid-cols-5 lg:p-6">
            <label class="fieldset gap-1 lg:col-span-2">
                <span class="fieldset-legend">Target</span>
                <input class="input w-full" type="search" name="target" value="{{ request('target') }}" placeholder="Nation, leader, or city name">
            </label>
            <label class="fieldset gap-1">
                <span class="fieldset-legend">Acknowledgement</span>
                <select class="select w-full" name="acknowledgement">
                    <option value="">Any state</option>
                    <option value="unacknowledged" @selected(request('acknowledgement') === 'unacknowledged')>Not acknowledged</option>
                    <option value="acknowledged" @selected(request('acknowledgement') === 'acknowledged')>Acknowledged</option>
                </select>
            </label>
            <label class="fieldset gap-1">
                <span class="fieldset-legend">Waiver</span>
                <select class="select w-full" name="waiver">
                    <option value="">Any state</option>
                    <option value="active" @selected(request('waiver') === 'active')>Active waiver</option>
                    <option value="none" @selected(request('waiver') === 'none')>No active waiver</option>
                </select>
            </label>
            <label class="fieldset gap-1">
                <span class="fieldset-legend">Due state</span>
                <select class="select w-full" name="due">
                    <option value="">Any due state</option>
                    <option value="overdue" @selected(request('due') === 'overdue')>Overdue</option>
                    <option value="upcoming" @selected(request('due') === 'upcoming')>Upcoming</option>
                    <option value="none" @selected(request('due') === 'none')>No due date</option>
                </select>
            </label>
            <div class="flex items-end justify-end gap-2 md:col-span-2 lg:col-span-5">
                <a class="btn btn-ghost" href="{{ route('admin.audits.rules.violations', $rule) }}">Clear</a>
                <button class="btn btn-outline btn-primary" type="submit">Apply filters</button>
            </div>
        </form>

        <div class="hidden overflow-x-auto md:block">
            <table class="table" data-sortable="true">
                <thead>
                <tr>
                    <th scope="col">Target</th>
                    <th scope="col" data-sortable="false">Match reason</th>
                    <th scope="col">Remediation state</th>
                    <th scope="col">Last checked</th>
                </tr>
                </thead>
                <tbody>
                @forelse($violations as $finding)
                    @php
                        $evidence = $evidenceFor($finding);
                        $primaryEvidence = $evidence->first(fn ($item) => ($item['scope'] ?? 'criteria') === 'criteria' && ($item['matched'] ?? false)) ?? $evidence->first();
                        $overdue = $finding->due_at?->isPast() ?? false;
                    @endphp
                    <tr class="align-top">
                        <td>
                            <a
                                href="{{ $finding->target_type->value === 'city' ? 'https://politicsandwar.com/city/id='.$finding->city_id : 'https://politicsandwar.com/nation/id='.$finding->nation_id }}"
                                target="_blank"
                                rel="noopener"
                                class="link link-primary font-semibold"
                            >{{ $targetName($finding) }}</a>
                            <p class="mt-1 text-sm text-base-content/60">{{ $finding->nation?->leader_name }} · {{ $finding->nation?->nation_name }}</p>
                        </td>
                        <td class="max-w-prose whitespace-normal">
                            <p class="font-medium">{{ $primaryEvidence['condition'] ?? data_get($finding->details, 'summary', 'Evidence will be populated on the next evaluation.') }}</p>
                            @if(is_array($primaryEvidence))
                                <p class="mt-2 text-sm text-base-content/60">
                                    Observed <strong>{{ $primaryEvidence['observed_display'] ?? 'Unavailable' }}</strong>
                                    · Expected <strong>{{ $primaryEvidence['expected_display'] ?? '—' }}</strong>
                                </p>
                            @endif

                            <details class="mt-3">
                                <summary class="cursor-pointer font-semibold text-primary">Evidence and remediation</summary>
                                <div class="mt-4 grid gap-4">
                                    @if($evidence->isNotEmpty())
                                        <div class="divide-y divide-base-300 border-y border-base-300">
                                            @foreach($evidence as $item)
                                                <div class="grid gap-2 py-3">
                                                    <p class="font-medium">{{ $item['condition'] ?? 'Audit condition' }}</p>
                                                    <p class="text-sm text-base-content/60">Observed {{ $item['observed_display'] ?? 'Unavailable' }} · Expected {{ $item['expected_display'] ?? '—' }} · {{ ($item['matched'] ?? false) ? 'Matched' : 'Did not match' }}</p>
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif

                                    <form method="POST" action="{{ route('admin.audits.results.remediation', $finding) }}" class="grid gap-3">
                                        @csrf
                                        @method('PATCH')
                                        <div class="grid gap-3 lg:grid-cols-2">
                                            <label class="fieldset gap-1">
                                                <span class="fieldset-legend">Due date</span>
                                                <input class="input w-full" type="datetime-local" name="due_at" value="{{ $finding->due_at?->format('Y-m-d\TH:i') }}">
                                            </label>
                                            <label class="fieldset gap-1">
                                                <span class="fieldset-legend">Waived until</span>
                                                <input class="input w-full" type="datetime-local" name="waived_until" value="{{ $finding->waived_until?->format('Y-m-d\TH:i') }}">
                                            </label>
                                        </div>
                                        <label class="fieldset gap-1">
                                            <span class="fieldset-legend">Remediation note</span>
                                            <input class="input w-full" name="remediation_note" maxlength="500" value="{{ $finding->remediation_note }}" placeholder="Optional internal follow-up note">
                                        </label>
                                        <div class="flex flex-wrap items-center justify-between gap-3">
                                            <label class="flex min-h-11 cursor-pointer items-center gap-2">
                                                <input class="checkbox" type="checkbox" name="clear_waiver" value="1">
                                                <span>Clear current waiver</span>
                                            </label>
                                            <button class="btn btn-primary" type="submit">Save remediation</button>
                                        </div>
                                    </form>
                                </div>
                            </details>
                        </td>
                        <td>
                            <div class="flex flex-wrap gap-2">
                                @if($overdue)<span class="badge badge-error">Overdue</span>@endif
                                @if($finding->acknowledged_at)<span class="badge badge-success badge-soft">Acknowledged</span>@endif
                                @if($finding->isWaived())<span class="badge badge-warning">Waived</span>@endif
                                @if($finding->isSnoozed())<span class="badge badge-info">Snoozed</span>@endif
                                @if(!$overdue && !$finding->acknowledged_at && !$finding->isWaived())<span class="badge badge-ghost">Open</span>@endif
                            </div>
                            @if($finding->due_at)<p class="mt-2 text-sm text-base-content/60">Due {{ $finding->due_at->toFormattedDateString() }}</p>@endif
                        </td>
                        <td data-order="{{ $finding->last_evaluated_at?->timestamp ?? 0 }}">
                            <div class="font-semibold">{{ $finding->last_evaluated_at?->diffForHumans() ?? 'Not checked' }}</div>
                            <p class="mt-1 text-sm text-base-content/60">Revision {{ $finding->rule_revision }}</p>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="py-8 text-center text-base-content/60">No current findings match these filters.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="divide-y divide-base-300 md:hidden">
            @forelse($violations as $finding)
                @php
                    $evidence = $evidenceFor($finding);
                    $primaryEvidence = $evidence->first(fn ($item) => ($item['scope'] ?? 'criteria') === 'criteria' && ($item['matched'] ?? false)) ?? $evidence->first();
                    $overdue = $finding->due_at?->isPast() ?? false;
                @endphp
                <article class="grid gap-4 p-4">
                    <div>
                        <div class="flex flex-wrap gap-2">
                            @if($overdue)<span class="badge badge-error">Overdue</span>@endif
                            @if($finding->acknowledged_at)<span class="badge badge-success badge-soft">Acknowledged</span>@endif
                            @if($finding->isWaived())<span class="badge badge-warning">Waived</span>@endif
                        </div>
                        <h3 class="mt-3 text-xl font-semibold">{{ $targetName($finding) }}</h3>
                        <p class="mt-1 text-sm text-base-content/60">{{ $finding->nation?->leader_name }} · {{ $finding->nation?->nation_name }}</p>
                    </div>
                    <div>
                        <p class="font-medium">{{ $primaryEvidence['condition'] ?? data_get($finding->details, 'summary', 'Evidence will be populated on the next evaluation.') }}</p>
                        @if(is_array($primaryEvidence))
                            <dl class="mt-3 grid grid-cols-2 gap-3 text-sm">
                                <div><dt class="text-base-content/60">Observed</dt><dd class="mt-1 font-semibold">{{ $primaryEvidence['observed_display'] ?? 'Unavailable' }}</dd></div>
                                <div><dt class="text-base-content/60">Expected</dt><dd class="mt-1 font-semibold">{{ $primaryEvidence['expected_display'] ?? '—' }}</dd></div>
                            </dl>
                        @endif
                    </div>
                    <details>
                        <summary class="cursor-pointer font-semibold text-primary">Evidence and remediation</summary>
                        <div class="mt-4 grid gap-4">
                            @foreach($evidence as $item)
                                <div class="border-t border-base-300 pt-3">
                                    <p>{{ $item['condition'] ?? 'Audit condition' }}</p>
                                    <p class="mt-1 text-sm text-base-content/60">{{ $item['observed_display'] ?? 'Unavailable' }} → {{ $item['expected_display'] ?? '—' }}</p>
                                </div>
                            @endforeach
                            <form method="POST" action="{{ route('admin.audits.results.remediation', $finding) }}" class="grid gap-3 border-t border-base-300 pt-4">
                                @csrf
                                @method('PATCH')
                                <label class="fieldset gap-1"><span class="fieldset-legend">Due date</span><input class="input w-full" type="datetime-local" name="due_at" value="{{ $finding->due_at?->format('Y-m-d\TH:i') }}"></label>
                                <label class="fieldset gap-1"><span class="fieldset-legend">Waived until</span><input class="input w-full" type="datetime-local" name="waived_until" value="{{ $finding->waived_until?->format('Y-m-d\TH:i') }}"></label>
                                <label class="fieldset gap-1"><span class="fieldset-legend">Remediation note</span><input class="input w-full" name="remediation_note" maxlength="500" value="{{ $finding->remediation_note }}"></label>
                                <label class="flex min-h-11 cursor-pointer items-center gap-2"><input class="checkbox" type="checkbox" name="clear_waiver" value="1"><span>Clear current waiver</span></label>
                                <button class="btn btn-primary w-full" type="submit">Save remediation</button>
                            </form>
                        </div>
                    </details>
                    <p class="text-sm text-base-content/60">Last checked {{ $finding->last_evaluated_at?->diffForHumans() ?? 'not yet' }} · Revision {{ $finding->rule_revision }}</p>
                </article>
            @empty
                <p class="p-6 text-center text-base-content/60">No current findings match these filters.</p>
            @endforelse
        </div>
    </section>
@endsection
