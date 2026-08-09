@php
    $sourceTimezone = (string) config('app.timezone', 'UTC');
    $defaultAutomations = collect(\App\Enums\MemberInactivityAutomation::cases())->pluck('value')->all();
    $selectedCreateAutomations = old('affected_automations', $defaultAutomations);
@endphp

<section id="inactivity-exceptions" class="mb-6 scroll-mt-6" aria-labelledby="inactivity-exceptions-heading">
    <x-card>
        <div class="flex flex-col gap-3 border-b border-base-300 pb-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 id="inactivity-exceptions-heading" class="text-lg font-semibold text-base-content">Leave and inactivity exceptions</h2>
                <p class="mt-1 max-w-3xl text-sm nexus-text-muted">
                    Set a start and end time, then choose which automatic actions to pause. Times are shown in {{ $sourceTimezone }}.
                </p>
            </div>
            <span class="badge badge-outline">{{ $memberInactivityExceptions->count() }} {{ str('exception')->plural($memberInactivityExceptions->count()) }} recorded</span>
        </div>

        <div class="mt-5 grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(22rem,0.8fr)] xl:items-start">
            <div class="space-y-4">
                @forelse($memberInactivityExceptions as $exception)
                    @php
                        $isRevoked = $exception->revoked_at !== null;
                        $isExpired = ! $isRevoked && ($exception->expired_at !== null || $exception->ends_at->lte(now()));
                        $isUpcoming = ! $isRevoked && ! $isExpired && $exception->starts_at->gt(now());
                        $isEditable = ! $isRevoked && ! $isExpired;
                        $statusLabel = match (true) {
                            $isRevoked => 'Revoked',
                            $isExpired => 'Expired',
                            $isUpcoming => 'Scheduled',
                            default => 'Active',
                        };
                        [$statusIntent, $statusIcon] = match ($statusLabel) {
                            'Active' => ['active', 'bolt'],
                            'Scheduled' => ['pending', 'clock'],
                            'Revoked' => ['failure', 'x-circle'],
                            default => ['neutral', 'archive-box'],
                        };
                        $startsLocal = $exception->starts_at->copy()->setTimezone($exception->timezone);
                        $endsLocal = $exception->ends_at->copy()->setTimezone($exception->timezone);
                        $selectedAutomations = $exception->affected_automations->pluck('value')->all();
                    @endphp

                    <article class="rounded-box border border-base-300 bg-base-100 p-4" aria-labelledby="exception-{{ $exception->id }}-heading">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 id="exception-{{ $exception->id }}-heading" class="font-semibold text-base-content">
                                        {{ $exception->category->label() }}
                                    </h3>
                                    <x-nexus-status :label="$statusLabel" :intent="$statusIntent" :icon="$statusIcon" />
                                </div>
                                <p class="mt-1 text-sm nexus-text-muted">
                                    {{ $startsLocal->format('M j, Y g:i A T') }} through {{ $endsLocal->format('M j, Y g:i A T') }}
                                </p>
                            </div>
                            <span class="text-xs nexus-text-muted">#{{ $exception->id }}</span>
                        </div>

                        <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                            <div class="sm:col-span-2">
                                <dt class="font-medium text-base-content">What the member sees</dt>
                                <dd class="mt-1 text-base-content/75">{{ $exception->member_reason }}</dd>
                            </div>
                            <div class="sm:col-span-2">
                                <dt class="font-medium text-base-content">Paused automatic actions</dt>
                                <dd class="mt-2 flex flex-wrap gap-2">
                                    @foreach($exception->affected_automations as $automation)
                                        <span class="badge badge-outline badge-sm">{{ $automation->label() }}</span>
                                    @endforeach
                                </dd>
                            </div>
                            <div>
                                <dt class="font-medium text-base-content">Approved</dt>
                                <dd class="mt-1 nexus-text-muted">
                                    {{ $exception->approver?->name ?? 'Former staff member' }}
                                    <span aria-hidden="true">&middot;</span>
                                    <x-time.display :value="$exception->approved_at" :server-now="now()" />
                                </dd>
                            </div>
                            <div>
                                <dt class="font-medium text-base-content">Last reviewed</dt>
                                <dd class="mt-1 nexus-text-muted">
                                    {{ $exception->lastReviewer?->name ?? 'Former staff member' }}
                                    <span aria-hidden="true">&middot;</span>
                                    <x-time.display :value="$exception->last_reviewed_at" :server-now="now()" />
                                </dd>
                            </div>
                            @if($exception->private_notes)
                                <div class="sm:col-span-2 rounded-box border border-warning/30 bg-warning/10 p-3">
                                    <dt class="font-medium text-base-content">Private staff notes</dt>
                                    <dd class="mt-1 whitespace-pre-line text-base-content/75">{{ $exception->private_notes }}</dd>
                                </div>
                            @endif
                            @if($isRevoked)
                                <div class="sm:col-span-2">
                                    <dt class="font-medium text-base-content">Revocation</dt>
                                    <dd class="mt-1 text-base-content/75">
                                        {{ $exception->revocation_reason }}
                                        @if($exception->revokedBy)
                                            <span aria-hidden="true">&middot;</span> {{ $exception->revokedBy->name }}
                                        @endif
                                    </dd>
                                </div>
                            @endif
                        </dl>

                        @if($isEditable)
                            <div class="mt-4 grid gap-3 lg:grid-cols-2">
                                <details class="rounded-box border border-base-300 p-3">
                                    <summary class="cursor-pointer font-medium text-base-content">Review or extend</summary>
                                    <p class="mt-2 text-sm nexus-text-muted">
                                        After an exception starts, you can extend its end time but cannot change when it started, its category, or its time zone.
                                    </p>
                                    <form method="POST" action="{{ route('admin.members.inactivity-exceptions.update', [$nation, $exception]) }}" class="mt-4 space-y-3">
                                        @csrf
                                        @method('PUT')
                                        <input type="hidden" name="timezone" value="{{ $exception->timezone }}">

                                        <label class="form-control w-full">
                                            <span class="label-text font-medium">Category</span>
                                            <select name="category" class="select select-bordered w-full" required>
                                                @foreach(\App\Enums\MemberInactivityExceptionCategory::cases() as $category)
                                                    <option value="{{ $category->value }}" @selected($category === $exception->category)>{{ $category->label() }}</option>
                                                @endforeach
                                            </select>
                                        </label>

                                        <div class="grid gap-3 sm:grid-cols-2">
                                            <label class="form-control w-full">
                                                <span class="label-text font-medium">Starts</span>
                                                <input type="datetime-local" name="starts_at" value="{{ $startsLocal->format('Y-m-d\TH:i') }}" class="input input-bordered w-full" required>
                                            </label>
                                            <label class="form-control w-full">
                                                <span class="label-text font-medium">Ends</span>
                                                <input type="datetime-local" name="ends_at" value="{{ $endsLocal->format('Y-m-d\TH:i') }}" class="input input-bordered w-full" required>
                                            </label>
                                        </div>

                                        <label class="form-control w-full">
                                            <span class="label-text font-medium">What the member sees</span>
                                            <textarea name="member_reason" class="textarea textarea-bordered w-full" rows="3" required>{{ $exception->member_reason }}</textarea>
                                        </label>
                                        <label class="form-control w-full">
                                            <span class="label-text font-medium">Private staff notes</span>
                                            <textarea name="private_notes" class="textarea textarea-bordered w-full" rows="3">{{ $exception->private_notes }}</textarea>
                                        </label>

                                        <fieldset>
                                            <legend class="font-medium text-base-content">Paused automatic actions</legend>
                                            <div class="mt-2 grid gap-2">
                                                @foreach(\App\Enums\MemberInactivityAutomation::cases() as $automation)
                                                    <label class="flex items-start gap-2 text-sm">
                                                        <input type="checkbox" name="affected_automations[]" value="{{ $automation->value }}" class="checkbox checkbox-sm" @checked(in_array($automation->value, $selectedAutomations, true))>
                                                        <span>{{ $automation->label() }}</span>
                                                    </label>
                                                @endforeach
                                            </div>
                                        </fieldset>

                                        <button type="submit" class="btn btn-primary btn-sm">Save changes</button>
                                    </form>
                                </details>

                                <details class="rounded-box border border-error/30 p-3">
                                    <summary class="cursor-pointer font-medium text-error">End early</summary>
                                    <form method="POST" action="{{ route('admin.members.inactivity-exceptions.destroy', [$nation, $exception]) }}" class="mt-4 space-y-3">
                                        @csrf
                                        @method('DELETE')
                                        <label class="form-control w-full">
                                            <span class="label-text font-medium">Reason for ending early</span>
                                            <textarea name="revocation_reason" class="textarea textarea-bordered w-full" rows="3" required></textarea>
                                        </label>
                                        <button type="submit" class="btn btn-error btn-sm">End exception</button>
                                    </form>
                                </details>
                            </div>
                        @endif
                    </article>
                @empty
                    <div class="rounded-box border border-dashed border-base-300 p-5 text-sm nexus-text-muted">
                        No leave or inactivity exceptions have been recorded for this member.
                    </div>
                @endforelse
            </div>

            <div class="rounded-box border border-base-300 bg-base-100 p-4">
                <h3 class="font-semibold text-base-content">Add an exception</h3>
                <p class="mt-1 text-sm nexus-text-muted">An end time is required. Back-to-back exceptions are allowed, but their dates cannot overlap.</p>

                <x-form.error-summary
                    class="mt-4"
                    :field-ids="[
                        'category' => 'exception-category',
                        'starts_at' => 'exception-starts-at',
                        'ends_at' => 'exception-ends-at',
                        'member_reason' => 'exception-member-reason',
                        'private_notes' => 'exception-private-notes',
                    ]"
                />

                <form method="POST" action="{{ route('admin.members.inactivity-exceptions.store', $nation) }}" class="mt-4 space-y-4">
                    @csrf
                    <input type="hidden" name="timezone" value="{{ $sourceTimezone }}">

                    <x-form.select id="exception-category" name="category" label="Category" required>
                        @foreach(\App\Enums\MemberInactivityExceptionCategory::cases() as $category)
                            <option value="{{ $category->value }}" @selected(old('category') === $category->value)>{{ $category->label() }}</option>
                        @endforeach
                    </x-form.select>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-form.input
                            id="exception-starts-at"
                            name="starts_at"
                            type="datetime-local"
                            label="Starts"
                            :value="now()->setTimezone($sourceTimezone)->startOfHour()->format('Y-m-d\TH:i')"
                            required
                        />
                        <x-form.input
                            id="exception-ends-at"
                            name="ends_at"
                            type="datetime-local"
                            label="Ends"
                            :value="now()->setTimezone($sourceTimezone)->addWeek()->startOfHour()->format('Y-m-d\TH:i')"
                            required
                        />
                    </div>

                    <x-form.textarea
                        id="exception-member-reason"
                        name="member_reason"
                        label="What the member sees"
                        hint="Explain why the exception exists and what the member should expect. Do not include confidential details."
                        rows="4"
                        required
                    />
                    <x-form.textarea
                        id="exception-private-notes"
                        name="private_notes"
                        label="Private staff notes"
                        hint="Only staff who can manage inactivity exceptions can see these notes."
                        rows="4"
                        optional
                    />

                    <fieldset>
                        <legend class="font-medium text-base-content">Automatic actions to pause <span aria-hidden="true">*</span></legend>
                        <p class="mt-1 text-sm nexus-text-muted">Actions you do not select will continue normally.</p>
                        <div class="mt-3 grid gap-2">
                            @foreach(\App\Enums\MemberInactivityAutomation::cases() as $automation)
                                <label class="flex items-start gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        name="affected_automations[]"
                                        value="{{ $automation->value }}"
                                        class="checkbox checkbox-sm"
                                        @checked(in_array($automation->value, $selectedCreateAutomations, true))
                                    >
                                    <span>{{ $automation->label() }}</span>
                                </label>
                            @endforeach
                        </div>
                        @error('affected_automations')
                            <p class="mt-2 text-sm text-error" role="alert">{{ $message }}</p>
                        @enderror
                    </fieldset>

                    <button type="submit" class="btn btn-primary w-full">Add exception</button>
                </form>
            </div>
        </div>
    </x-card>
</section>
