@extends('layouts.admin')

@section('title', 'Recruitment Messaging')

@section('content')
    <div class="nexus-stack">
        <div class="nexus-page-header">
            <div class="nexus-page-header__copy">
                <p class="nexus-kicker">Membership Operations</p>
                <h1 class="nexus-page-title">Recruitment Messaging &amp; A/B Testing</h1>
                <p class="nexus-page-summary">
                    Manage recruitment message variants, measure conversion rates with unique apply links, and balance automated outreach across candidates.
                </p>
            </div>
            <div class="nexus-page-header__actions flex flex-wrap items-center gap-2">
                <x-nexus-status
                    :label="$recruitmentEnabled ? 'Automatic outreach active' : 'Automatic outreach paused'"
                    :intent="$recruitmentEnabled ? 'active' : 'warning'"
                    :icon="$recruitmentEnabled ? 'bolt' : 'minus-circle'"
                />

                <button
                    type="button"
                    class="btn btn-primary btn-sm"
                    onclick="document.getElementById('createMessageModal').showModal()"
                >
                    <x-icon name="o-plus" class="size-4" />
                    New Message
                </button>

                <form method="POST" action="{{ route('admin.recruitment.reset-cohort') }}" onsubmit="return confirm('Reset current test sends and clicks to 0 across all variants? Lifetime stats will be preserved.')">
                    @csrf
                    <button type="submit" class="btn btn-outline btn-sm">
                        <x-icon name="o-arrow-path" class="size-4" />
                        Reset Current Test
                    </button>
                </form>
            </div>
        </div>

        {{-- Connected Metrics Summary --}}
        <dl class="nexus-metrics" aria-label="Recruitment performance summary">
            <div class="nexus-metric">
                <dt class="nexus-stat-label">Active Variants</dt>
                <dd class="nexus-stat-value tabular-nums">{{ $activeVariantsCount }}</dd>
                <p class="nexus-stat-helper">{{ $variants->count() }} total templates</p>
            </div>
            <div class="nexus-metric">
                <dt class="nexus-stat-label">Current Test Sends</dt>
                <dd class="nexus-stat-value tabular-nums">{{ number_format($totalCurrentSends) }}</dd>
                <p class="nexus-stat-helper">
                    @if($cohortStartedAt)
                        Since {{ $cohortStartedAt->diffForHumans() }}
                    @else
                        Active test period
                    @endif
                </p>
            </div>
            <div class="nexus-metric">
                <dt class="nexus-stat-label">Current Test Clicks</dt>
                <dd class="nexus-stat-value tabular-nums">{{ number_format($totalCurrentClicks) }}</dd>
                <p class="nexus-stat-helper">Cohort CTR: {{ $currentCohortCtr }}%</p>
            </div>
            <div class="nexus-metric">
                <dt class="nexus-stat-label">Lifetime Activity</dt>
                <dd class="nexus-stat-value tabular-nums">{{ number_format($totalLifetimeSends) }} / {{ number_format($totalLifetimeClicks) }}</dd>
                <p class="nexus-stat-helper">Lifetime CTR: {{ $lifetimeCtr }}%</p>
            </div>
        </dl>

        <div class="grid scroll-mt-24 gap-6 xl:grid-cols-[minmax(0,2fr)_minmax(340px,1fr)]">
            <div class="space-y-6">
                {{-- A/B Testing Message Variants Card --}}
                <x-card title="A/B Testing Message Pool">
                    <x-slot:menu>
                        <span class="text-xs nexus-text-muted">
                            Current metrics reset automatically whenever a new message is added.
                        </span>
                    </x-slot:menu>

                    @if($variants->isEmpty())
                        <div class="nexus-empty-state text-center py-8">
                            <x-icon name="o-envelope" class="mx-auto size-12 text-base-content/40" />
                            <h3 class="mt-2 text-sm font-semibold text-base-content">No message variants configured</h3>
                            <p class="mt-1 text-sm text-base-content/70">Create individual recruitment messages to begin A/B testing outreach.</p>
                            <div class="mt-4">
                                <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('createMessageModal').showModal()">
                                    Add your first message
                                </button>
                            </div>
                        </div>
                    @else
                        <div class="overflow-x-auto rounded-box border border-base-300">
                            <table class="table table-zebra table-sm" data-sortable="false">
                                <thead>
                                    <tr>
                                        <th scope="col">Variant</th>
                                        <th scope="col">Status</th>
                                        <th scope="col">Apply Link</th>
                                        <th scope="col" class="text-right">Current Sends</th>
                                        <th scope="col" class="text-right">Current Clicks</th>
                                        <th scope="col" class="text-right">Current CTR</th>
                                        <th scope="col" class="text-right">Lifetime</th>
                                        <th scope="col" class="text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($variants as $variant)
                                        @php
                                            $currentSendShare = $totalCurrentSends > 0
                                                ? round(($variant->current_sends / $totalCurrentSends) * 100, 1)
                                                : 0.0;
                                            $currentClickShare = $totalCurrentClicks > 0
                                                ? round(($variant->current_clicks / $totalCurrentClicks) * 100, 1)
                                                : 0.0;
                                        @endphp
                                        <tr class="{{ ! $variant->is_active ? 'opacity-60 bg-base-200/40' : '' }}">
                                            <td>
                                                <div class="font-medium text-base-content">{{ $variant->name }}</div>
                                                <div class="text-xs nexus-text-muted truncate max-w-xs">{{ $variant->subject }}</div>
                                            </td>
                                            <td>
                                                <span class="badge badge-sm {{ $variant->is_active ? 'badge-success' : 'badge-ghost' }}">
                                                    {{ $variant->is_active ? 'Active' : 'Paused' }}
                                                </span>
                                            </td>
                                            <td>
                                                <div class="flex items-center gap-1">
                                                    <input
                                                        type="text"
                                                        readonly
                                                        value="{{ $variant->tracking_url }}"
                                                        class="input input-xs input-bordered w-36 font-mono text-xs select-all"
                                                    />
                                                    <button
                                                        type="button"
                                                        class="btn btn-ghost btn-xs"
                                                        title="Copy tracking link"
                                                        onclick="navigator.clipboard.writeText('{{ $variant->tracking_url }}'); this.textContent = '✓'; setTimeout(() => this.textContent = 'Copy', 2000)"
                                                    >
                                                        Copy
                                                    </button>
                                                </div>
                                            </td>
                                            <td class="text-right font-mono tabular-nums">
                                                <span>{{ number_format($variant->current_sends) }}</span>
                                                <span class="text-xs text-base-content/60">({{ $currentSendShare }}%)</span>
                                            </td>
                                            <td class="text-right font-mono tabular-nums">
                                                <span>{{ number_format($variant->current_clicks) }}</span>
                                                <span class="text-xs text-base-content/60">({{ $currentClickShare }}%)</span>
                                            </td>
                                            <td class="text-right font-mono tabular-nums font-semibold {{ $variant->current_ctr >= $currentCohortCtr ? 'text-success' : 'text-base-content' }}">
                                                {{ $variant->current_ctr }}%
                                            </td>
                                            <td class="text-right font-mono tabular-nums text-xs">
                                                <div>{{ number_format($variant->lifetime_sends) }}s / {{ number_format($variant->lifetime_clicks) }}c</div>
                                                <div class="text-base-content/60">{{ $variant->lifetime_ctr }}% CTR</div>
                                            </td>
                                            <td class="text-right">
                                                <div class="flex items-center justify-end gap-1">
                                                    <button
                                                        type="button"
                                                        class="btn btn-ghost btn-xs"
                                                        onclick="document.getElementById('editMessageModal-{{ $variant->id }}').showModal()"
                                                        title="Edit variant"
                                                    >
                                                        Edit
                                                    </button>

                                                    <form method="POST" action="{{ route('admin.recruitment.messages.toggle', $variant) }}">
                                                        @csrf
                                                        <button type="submit" class="btn btn-ghost btn-xs" title="{{ $variant->is_active ? 'Pause variant' : 'Activate variant' }}">
                                                            {{ $variant->is_active ? 'Pause' : 'Activate' }}
                                                        </button>
                                                    </form>

                                                    <form method="POST" action="{{ route('admin.recruitment.messages.destroy', $variant) }}" onsubmit="return confirm('Remove variant \'{{ $variant->name }}\'?')">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit" class="btn btn-ghost btn-xs text-error" title="Delete variant">
                                                            Delete
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </x-card>

                {{-- General Outreach & Follow-up Settings Card --}}
                <x-card title="Automated Outreach &amp; Follow-up Settings">
                    <form id="recruitment-settings" method="POST" action="{{ route('admin.recruitment.update') }}" class="space-y-5">
                        @csrf

                        <x-form.toggle
                            id="recruitment_enabled"
                            label="Enable automatic recruitment messages"
                            hint="When enabled, scheduled cycles send active variants to newly eligible nations in an A/B rotation."
                            name="recruitment_enabled"
                            value="1"
                            :checked="old('recruitment_enabled', $recruitmentEnabled)"
                        />

                        <x-form.toggle
                            id="follow_up_enabled"
                            :label="'Enable follow-up message (sent ' . \App\Services\RecruitmentService::FOLLOW_UP_DELAY_HOURS . ' hours later)'"
                            name="follow_up_enabled"
                            value="1"
                            :checked="old('follow_up_enabled', $followUpEnabled)"
                        />

                        <x-input
                            id="follow_up_subject"
                            label="Follow-up subject"
                            name="follow_up_subject"
                            :value="old('follow_up_subject', $followUpSubject)"
                            error-field="follow_up_subject"
                            hint="Maximum 50 characters (in-game limit)."
                            maxlength="50"
                            required
                        />

                        <x-textarea
                            id="follow_up_message"
                            label="Follow-up message"
                            name="follow_up_message"
                            class="js-jodit"
                            error-field="follow_up_message"
                            hint="The follow-up is only sent if the recruit has not joined an alliance after 60 hours."
                            rows="8"
                            required
                        >{{ old('follow_up_message', $followUpMessage) }}</x-textarea>

                        <div class="flex justify-end">
                            <button type="submit" class="btn btn-primary">
                                Save settings
                            </button>
                        </div>
                    </form>
                </x-card>
            </div>

            <div class="space-y-6">
                {{-- Send Test Message Card --}}
                <x-card title="Send Test Message">
                    @if($userNationId)
                        <p class="text-sm nexus-text-muted">
                            Test messages are sent to your nation (ID {{ $userNationId }}).
                        </p>

                        <form method="POST" action="{{ route('admin.recruitment.test') }}" class="space-y-4 mt-3">
                            @csrf

                            <div>
                                <label for="test_type" class="fieldset-legend mb-0.5">Message target <span class="text-error">*</span></label>
                                <select id="test_type" name="type" class="select w-full" onchange="document.getElementById('variantSelectGroup').style.display = (this.value === 'variant') ? 'block' : 'none';" required>
                                    <option value="variant" @selected(old('type') === 'variant')>A/B Recruitment Variant</option>
                                    <option value="follow_up" @selected(old('type') === 'follow_up')>Follow-up Message</option>
                                </select>
                            </div>

                            <div id="variantSelectGroup" style="{{ old('type') === 'follow_up' ? 'display: none;' : 'display: block;' }}">
                                <label for="message_id" class="fieldset-legend mb-0.5">Select variant</label>
                                <select id="message_id" name="message_id" class="select w-full">
                                    @foreach($variants as $variant)
                                        <option value="{{ $variant->id }}" @selected(old('message_id') == $variant->id)>
                                            {{ $variant->name }} ({{ $variant->subject }})
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            <button type="submit" class="btn btn-outline btn-primary w-full">
                                Send test message
                            </button>
                        </form>
                    @else
                        <div class="alert alert-warning">
                            Add your nation ID to your profile to send test messages.
                        </div>
                    @endif
                </x-card>

                {{-- Latest Recruited Nations Card --}}
                <x-card title="Latest Recruited Nations">
                    <div class="overflow-x-auto rounded-box border border-base-300">
                        <table class="table table-zebra table-xs" data-sortable="false">
                            <thead>
                                <tr>
                                    <th scope="col">Leader</th>
                                    <th scope="col">Variant</th>
                                    <th scope="col">Sent</th>
                                    <th scope="col">Follow Up</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($latestNations as $nation)
                                    <tr>
                                        <td>
                                            <div class="flex items-center gap-1.5">
                                                <a href="https://politicsandwar.com/nation/id={{ $nation->nation_id }}" target="_blank" rel="noopener" class="link link-hover font-medium">
                                                    {{ $nation->nation?->leader_name ?? $nation->nation_id }}
                                                </a>
                                                <span class="badge badge-xs {{ $nation->nation?->alliance_id == $primaryAllianceId ? 'badge-success' : 'badge-ghost' }}">
                                                    {{ $nation->nation?->alliance_id == $primaryAllianceId ? 'Joined' : 'Pending' }}
                                                </span>
                                            </div>
                                        </td>
                                        <td class="text-xs truncate max-w-28" title="{{ $nation->recruitmentMessage?->name ?? 'Default' }}">
                                            {{ $nation->recruitmentMessage?->name ?? '—' }}
                                        </td>
                                        <td class="text-xs text-base-content/70">{{ $nation->primary_sent_at?->diffForHumans() ?? '—' }}</td>
                                        <td class="text-xs text-base-content/70">{{ $nation->follow_up_scheduled_for?->diffForHumans() ?? '—' }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="text-center text-xs py-4 text-base-content/60">No recent recruitment dispatches recorded.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </x-card>
            </div>
        </div>
    </div>

    {{-- Create Message Modal --}}
    <dialog id="createMessageModal" class="modal" aria-label="Create recruitment message variant">
        <div class="modal-box max-w-2xl">
            <div class="flex items-center justify-between border-b border-base-300 pb-3">
                <h3 class="font-bold text-lg">New Recruitment Message Variant</h3>
                <button type="button" class="btn btn-sm btn-circle btn-ghost" onclick="document.getElementById('createMessageModal').close()" aria-label="Close dialog">✕</button>
            </div>

            <p class="text-xs text-base-content/70 mt-3">
                Tip: Include <code class="rounded bg-base-200 px-1 py-0.5 font-mono text-primary">{apply_link}</code> in your message body to place the unique tracked apply link. Adding a new message will automatically reset current test metrics so all variants compete on an equal footing.
            </p>

            <form method="POST" action="{{ route('admin.recruitment.messages.store') }}" class="space-y-4 mt-4">
                @csrf

                <x-input
                    id="new_name"
                    label="Variant name"
                    name="name"
                    placeholder="e.g. Community Focus v1"
                    error-field="name"
                    required
                />

                <x-input
                    id="new_subject"
                    label="Subject line"
                    name="subject"
                    placeholder="e.g. Welcome to Politics & War!"
                    error-field="subject"
                    hint="Maximum 50 characters (in-game limit)."
                    maxlength="50"
                    required
                />

                <x-textarea
                    id="new_message"
                    label="Message body"
                    name="message"
                    class="js-jodit"
                    error-field="message"
                    hint="Use {apply_link} to embed the tracked application URL."
                    rows="8"
                    required
                >&lt;p&gt;Welcome to Politics &amp; War!&lt;/p&gt;&lt;p&gt;We would love to help you build your nation. &lt;a href="{apply_link}"&gt;Apply to join us here&lt;/a&gt;!&lt;/p&gt;</x-textarea>

                <x-form.toggle
                    id="new_is_active"
                    label="Active in A/B testing pool"
                    name="is_active"
                    value="1"
                    checked
                />

                <div class="modal-action">
                    <button type="button" class="btn btn-ghost" onclick="document.getElementById('createMessageModal').close()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Variant &amp; Reset Test</button>
                </div>
            </form>
        </div>
        <form method="dialog" class="modal-backdrop"><button>close</button></form>
    </dialog>

    {{-- Edit Message Modals --}}
    @foreach($variants as $variant)
        <dialog id="editMessageModal-{{ $variant->id }}" class="modal" aria-label="Edit recruitment message variant {{ $variant->name }}">
            <div class="modal-box max-w-2xl">
                <div class="flex items-center justify-between border-b border-base-300 pb-3">
                    <h3 class="font-bold text-lg">Edit Variant: {{ $variant->name }}</h3>
                    <button type="button" class="btn btn-sm btn-circle btn-ghost" onclick="document.getElementById('editMessageModal-{{ $variant->id }}').close()" aria-label="Close dialog">✕</button>
                </div>

                <div class="mt-3 p-2.5 rounded bg-base-200/60 text-xs flex items-center justify-between">
                    <div>
                        <span class="font-medium">Tracking Link:</span>
                        <code class="font-mono text-primary">{{ $variant->tracking_url }}</code>
                    </div>
                    <button
                        type="button"
                        class="btn btn-ghost btn-xs"
                        onclick="navigator.clipboard.writeText('{{ $variant->tracking_url }}'); this.textContent = '✓ Copied'; setTimeout(() => this.textContent = 'Copy', 2000)"
                    >
                        Copy
                    </button>
                </div>

                <form method="POST" action="{{ route('admin.recruitment.messages.update', $variant) }}" class="space-y-4 mt-4">
                    @csrf
                    @method('PUT')

                    <x-input
                        id="edit_name_{{ $variant->id }}"
                        label="Variant name"
                        name="name"
                        :value="old('name', $variant->name)"
                        error-field="name"
                        required
                    />

                    <x-input
                        id="edit_subject_{{ $variant->id }}"
                        label="Subject line"
                        name="subject"
                        :value="old('subject', $variant->subject)"
                        error-field="subject"
                        hint="Maximum 50 characters (in-game limit)."
                        maxlength="50"
                        required
                    />

                    <x-textarea
                        id="edit_message_{{ $variant->id }}"
                        label="Message body"
                        name="message"
                        class="js-jodit"
                        error-field="message"
                        hint="Use {apply_link} to embed the tracked application URL."
                        rows="8"
                        required
                    >{{ old('message', $variant->message) }}</x-textarea>

                    <x-form.toggle
                        id="edit_is_active_{{ $variant->id }}"
                        label="Active in A/B testing pool"
                        name="is_active"
                        value="1"
                        :checked="old('is_active', $variant->is_active)"
                    />

                    <div class="modal-action">
                        <button type="button" class="btn btn-ghost" onclick="document.getElementById('editMessageModal-{{ $variant->id }}').close()">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
            <form method="dialog" class="modal-backdrop"><button>close</button></form>
        </dialog>
    @endforeach
@endsection

@push('scripts')
    @vite('resources/js/jodit.js')
@endpush
