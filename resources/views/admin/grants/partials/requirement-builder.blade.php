@php
    $inputName = $inputName ?? 'validation_rules_json';
    $builderConfig = $builderConfig ?? [];
    $showValidationErrors = $showValidationErrors ?? false;
    $emptySummaryTitle = $emptySummaryTitle ?? 'No custom requirements configured';
    $emptySummaryHint = $emptySummaryHint ?? 'Applications will only enforce the standard eligibility checks until you add rules.';
@endphp

<div
    class="space-y-3"
    data-grant-requirement-builder
    data-empty-title="{{ $emptySummaryTitle }}"
    data-empty-hint="{{ $emptySummaryHint }}"
>
    <input type="hidden" name="{{ $inputName }}" data-grant-requirement-input>
    <script type="application/json" data-grant-requirement-config>@json($builderConfig)</script>

    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <div class="font-semibold">Eligibility Builder</div>
            <div class="text-sm text-base-content/50">Combine fields, comparisons, project checks, and nested logic.</div>
        </div>
        <div class="flex shrink-0 flex-wrap gap-2">
            <button type="button" data-grant-requirement-action="add-condition" class="btn btn-outline btn-primary btn-sm">Add Condition</button>
            <button type="button" data-grant-requirement-action="add-group" class="btn btn-outline btn-sm">Add Group</button>
        </div>
    </div>

    @if ($showValidationErrors && $errors->any())
        <x-alert class="alert-error" role="alert">
            <div class="mb-1 font-semibold">Please fix the highlighted grant form issues.</div>
            <ul class="list-inside list-disc text-sm">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </x-alert>
    @endif

    <div class="rounded-box border border-base-300 bg-base-200 p-3" aria-live="polite">
        <div class="mb-1 text-sm font-semibold text-base-content/70">Live summary</div>
        <div class="font-semibold" data-grant-requirement-rule-count>{{ $emptySummaryTitle }}</div>
        <div class="mt-1 text-sm text-base-content/50" data-grant-requirement-summary-hint>{{ $emptySummaryHint }}</div>
    </div>

    <label class="rounded-box block border border-base-300 bg-base-200 p-3">
        <span class="mb-2 flex items-center justify-between gap-3">
            <span class="text-sm font-semibold">Top-level logic</span>
            <span class="badge badge-neutral badge-sm" data-grant-requirement-root-badge>0 rules</span>
        </span>
        <select class="select select-sm w-full" data-grant-requirement-root-mode></select>
    </label>

    <div class="space-y-3" data-grant-requirement-rules></div>

    <div class="rounded-box bg-base-200 p-3">
        <div class="mb-2 text-sm font-semibold text-base-content/70">Builder tips</div>
        <ul class="list-inside list-disc space-y-1 text-sm text-base-content/60">
            <li>Use <strong>Any condition may match</strong> when several different paths should qualify.</li>
            <li>Use nested groups to combine project checks with city, score, or MMR ranges.</li>
            <li>Add a custom message on any condition to show a specific denial reason to the applicant.</li>
        </ul>
    </div>
</div>
