@php
    $priorityLabels = [
        'high' => 'High — urgent action',
        'medium' => 'Medium — should be addressed',
        'low' => 'Low — improvement recommended',
        'info' => 'Info — awareness only',
    ];
    $selectedTarget = old('target_type', $rule->target_type?->value ?? 'nation');
    $definitionValue = old('definition', $initialDefinition);

    if (is_string($definitionValue)) {
        $decodedDefinition = json_decode($definitionValue, true);
        $definitionValue = is_array($decodedDefinition) ? $decodedDefinition : $initialDefinition;
    }
@endphp

<input
    type="hidden"
    name="definition"
    value="{{ json_encode($definitionValue, JSON_UNESCAPED_SLASHES) }}"
    data-audit-definition-input
>
<input type="hidden" name="impact_confirmation_token" value="" data-audit-confirmation-token>

<script type="application/json" data-audit-rule-config>@json($builderConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)</script>

@if($rule->last_evaluation_status?->value === 'migration_failed' || ($rule->exists && $rule->definition === null))
    <div class="alert alert-warning items-start" role="alert">
        <x-icon name="o-exclamation-triangle" class="mt-1 size-5 shrink-0" aria-hidden="true" />
        <div>
            <div class="font-semibold">This imported rule needs to be rebuilt</div>
            <p class="text-sm">Its previous legacy check could not be converted safely. Add guided conditions below, test the impact, and enable it when it is ready.</p>
        </div>
    </div>
@endif

<div class="audit-rule-editor">
    <div class="audit-rule-editor__authoring nexus-panel">
        <section class="audit-rule-section" aria-labelledby="audit-rule-details-heading">
            <div class="audit-rule-section__heading">
                <span class="audit-rule-section__step" aria-hidden="true">1</span>
                <div>
                    <h2 id="audit-rule-details-heading" class="audit-rule-section__title">Rule details</h2>
                    <p class="audit-rule-section__description">Give admins a clear name and choose what this rule checks.</p>
                </div>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <label class="fieldset gap-1" for="audit-rule-name">
                    <span class="fieldset-legend">Rule name <span class="text-error">*</span></span>
                    <input
                        id="audit-rule-name"
                        class="input w-full @error('name') input-error @enderror"
                        name="name"
                        value="{{ old('name', $rule->name) }}"
                        maxlength="255"
                        placeholder="Example: Aircraft below readiness target"
                        required
                    >
                    @error('name')<span class="text-sm text-error">{{ $message }}</span>@enderror
                </label>

                <div class="fieldset gap-1">
                    <label class="fieldset-legend" for="audit-target-type">Target <span class="text-error">*</span></label>
                    <select
                        id="audit-target-type"
                        name="target_type"
                        class="select w-full @error('target_type') select-error @enderror"
                        data-audit-target
                        required
                    >
                        @foreach($targetTypes as $targetType)
                            <option value="{{ $targetType->value }}" @selected($selectedTarget === $targetType->value)>
                                {{ $targetType === \App\Enums\AuditTargetType::Nation ? 'Nation — one finding per nation' : 'City — one finding per matching city' }}
                            </option>
                        @endforeach
                    </select>
                    <p class="text-sm nexus-text-muted">Changing the target clears conditions that do not apply to the new target.</p>
                    @error('target_type')<p class="text-sm text-error">{{ $message }}</p>@enderror
                </div>

                <div class="fieldset gap-1">
                    <label class="fieldset-legend" for="audit-priority">Priority <span class="text-error">*</span></label>
                    <select id="audit-priority" name="priority" class="select w-full @error('priority') select-error @enderror" required>
                        @foreach($priorities as $priority)
                            <option value="{{ $priority->value }}" @selected(old('priority', $rule->priority?->value ?? 'medium') === $priority->value)>
                                {{ $priorityLabels[$priority->value] }}
                            </option>
                        @endforeach
                    </select>
                    @error('priority')<p class="text-sm text-error">{{ $message }}</p>@enderror
                </div>

                <div class="rounded-box border border-base-300 bg-base-200 p-4">
                    <label class="flex min-h-11 cursor-pointer items-start gap-3" for="enabledToggle">
                        <input
                            id="enabledToggle"
                            class="toggle toggle-primary mt-1"
                            type="checkbox"
                            name="enabled"
                            value="1"
                            data-audit-enabled
                            @checked(old('enabled', $rule->enabled ?? false))
                        >
                        <span>
                            <span class="block font-semibold">Enable scheduled evaluation</span>
                            <span class="mt-1 block text-sm nexus-text-muted">Activation always shows the current impact before it is confirmed.</span>
                        </span>
                    </label>
                </div>

                <div class="md:col-span-2">
                    <label class="fieldset gap-1" for="audit-admin-notes">
                        <span class="fieldset-legend">Admin notes</span>
                        <textarea
                            id="audit-admin-notes"
                            class="textarea w-full @error('admin_notes') textarea-error @enderror"
                            name="admin_notes"
                            rows="3"
                            maxlength="5000"
                            placeholder="Optional internal context, ownership, or follow-up notes"
                        >{{ old('admin_notes', $rule->admin_notes) }}</textarea>
                        @error('admin_notes')<span class="text-sm text-error">{{ $message }}</span>@enderror
                    </label>
                    <p class="mt-1 text-sm nexus-text-muted">Only admins can see these notes.</p>
                </div>
            </div>
        </section>

        <section class="audit-rule-section" aria-labelledby="audit-criteria-heading">
            <div class="audit-rule-section__heading">
                <span class="audit-rule-section__step" aria-hidden="true">2</span>
                <div>
                    <h2 id="audit-criteria-heading" class="audit-rule-section__title">Alert when</h2>
                    <p class="audit-rule-section__description">Describe the conditions that should open a finding.</p>
                </div>
            </div>

            <div data-audit-criteria></div>

            @error('definition')
                <div class="alert alert-error mt-4" role="alert">
                    <x-icon name="o-exclamation-circle" class="size-5 shrink-0" aria-hidden="true" />
                    <span>{{ $message }}</span>
                </div>
            @enderror
            <div class="alert alert-error mt-4" data-audit-definition-errors role="alert" tabindex="-1" hidden></div>
        </section>

        <section class="audit-rule-section" aria-labelledby="audit-exceptions-heading">
            <div class="audit-rule-section__heading">
                <span class="audit-rule-section__step" aria-hidden="true">3</span>
                <div>
                    <h2 id="audit-exceptions-heading" class="audit-rule-section__title">Except when</h2>
                    <p class="audit-rule-section__description">Optionally suppress a finding for understood, acceptable situations.</p>
                </div>
            </div>

            <details class="audit-rule-exceptions" data-audit-exceptions-disclosure @if(($definitionValue['exceptions']['rules'] ?? []) !== []) open @endif>
                <summary class="audit-rule-exceptions__summary">
                    <span data-audit-exceptions-summary>
                        {{ ($definitionValue['exceptions']['rules'] ?? []) !== [] ? 'Edit exceptions' : 'Add an exception' }}
                    </span>
                    <x-icon name="o-chevron-down" class="size-5" aria-hidden="true" />
                </summary>
                <div class="border-t border-base-300 p-4 md:p-6" data-audit-exceptions></div>
            </details>
        </section>

        <section class="audit-rule-section" aria-labelledby="audit-member-copy-heading">
            <div class="audit-rule-section__heading">
                <span class="audit-rule-section__step" aria-hidden="true">4</span>
                <div>
                    <h2 id="audit-member-copy-heading" class="audit-rule-section__title">What members see</h2>
                    <p class="audit-rule-section__description">Explain why this matters and what a member should do next.</p>
                </div>
            </div>

            <div class="grid gap-4">
                <label class="fieldset gap-1" for="audit-member-explanation">
                    <span class="fieldset-legend">Finding explanation</span>
                    <textarea
                        id="audit-member-explanation"
                        class="textarea w-full @error('description') textarea-error @enderror"
                        name="description"
                        rows="4"
                        maxlength="5000"
                        placeholder="Explain the policy or readiness concern in plain language"
                    >{{ old('description', $rule->description) }}</textarea>
                    @error('description')<span class="text-sm text-error">{{ $message }}</span>@enderror
                </label>

                <label class="fieldset gap-1" for="audit-remediation-guidance">
                    <span class="fieldset-legend">Remediation guidance</span>
                    <textarea
                        id="audit-remediation-guidance"
                        class="textarea w-full @error('remediation_guidance') textarea-error @enderror"
                        name="remediation_guidance"
                        rows="4"
                        maxlength="5000"
                        placeholder="Give concrete steps the member can take to resolve the finding"
                    >{{ old('remediation_guidance', $rule->remediation_guidance) }}</textarea>
                    @error('remediation_guidance')<span class="text-sm text-error">{{ $message }}</span>@enderror
                </label>
            </div>
        </section>
    </div>

    <aside class="audit-rule-review nexus-panel" aria-labelledby="audit-review-heading">
        <div class="audit-rule-review__inner">
            <div>
                <p class="nexus-kicker">Review</p>
                <h2 id="audit-review-heading" class="audit-rule-section__title">Rule impact</h2>
                <p class="mt-2 text-sm nexus-text-muted">The summary updates as you build. Test against current members whenever you want.</p>
            </div>

            <div class="audit-rule-review__block">
                <h3 class="text-sm font-semibold">Plain-language summary</h3>
                <p class="mt-2 max-w-prose text-base leading-6" data-audit-summary>{{ $initialSummary }}</p>
            </div>

            <div class="audit-rule-review__block" aria-live="polite">
                <div class="flex items-end justify-between gap-3">
                    <div>
                        <p class="text-sm font-semibold">Estimated findings</p>
                        <p class="mt-1 text-sm nexus-text-muted" data-audit-preview-status>Not tested yet</p>
                    </div>
                    <strong class="text-2xl tabular-nums" data-audit-match-count>—</strong>
                </div>
                <div class="mt-3 grid gap-2" data-audit-warnings hidden></div>
                <div class="mt-4 grid gap-2" data-audit-samples hidden></div>
            </div>

            @error('impact_confirmation_token')
                <div class="alert alert-warning" role="alert">
                    <x-icon name="o-exclamation-triangle" class="size-5 shrink-0" aria-hidden="true" />
                    <span>{{ $message }}</span>
                </div>
            @enderror

            <div class="audit-rule-review__actions">
                <button type="button" class="btn btn-outline w-full" data-audit-test>
                    <x-icon name="o-beaker" class="size-5" aria-hidden="true" />
                    Test rule
                </button>
                <button type="submit" class="btn btn-primary w-full" data-audit-submit>
                    <x-icon name="o-check" class="size-5" aria-hidden="true" />
                    {{ $rule->exists ? 'Save rule' : 'Create rule' }}
                </button>
                <a href="{{ route('admin.audits.rules.index') }}" class="btn btn-ghost w-full">Cancel</a>
            </div>
        </div>
    </aside>
</div>

<dialog class="modal" data-audit-impact-dialog aria-labelledby="audit-impact-title">
    <div class="modal-box max-w-2xl">
        <h2 id="audit-impact-title" class="text-2xl font-semibold">Confirm rule impact</h2>
        <p class="mt-2 text-base nexus-text-muted" data-audit-dialog-copy>
            Review the current impact before this rule is activated.
        </p>

        <div class="mt-6 grid gap-4">
            <div class="flex items-end justify-between gap-4 border-y border-base-300 py-4">
                <span class="font-semibold">Findings that will open now</span>
                    <strong class="text-2xl tabular-nums" data-audit-impact-count>—</strong>
                </div>
            <div data-audit-impact-reset-notice class="text-sm nexus-text-muted"></div>
            <div data-audit-impact-warnings hidden></div>
            <div data-audit-impact-samples hidden></div>
        </div>

        <div class="modal-action">
            <button type="button" class="btn btn-ghost" data-audit-impact-cancel>Go back</button>
            <button type="button" class="btn btn-primary" data-audit-impact-confirm>
                Confirm and save
            </button>
        </div>
    </div>
    <form method="dialog" class="modal-backdrop">
        <button aria-label="Close impact confirmation">close</button>
    </form>
</dialog>
