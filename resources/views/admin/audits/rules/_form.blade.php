@php
    $priorityLabels = [
        'high' => 'High (urgent)',
        'medium' => 'Medium',
        'low' => 'Low',
        'info' => 'Info only',
    ];
@endphp

<div class="grid gap-4 md:grid-cols-2">
    <div>
        <x-input
            label="Name"
            name="name"
            :value="old('name', $rule->name)"
            error-field="name"
            required
        />
    </div>
    <div>
        <label class="fieldset-legend mb-0.5">Target type <span class="text-error">*</span></label>
        <select name="target_type" class="select w-full @error('target_type') !select-error @enderror" required>
            @foreach($targetTypes as $targetType)
                <option value="{{ $targetType->value }}"
                        @selected(old('target_type', $rule->target_type?->value) === $targetType->value)>
                    {{ ucfirst($targetType->value) }}
                </option>
            @endforeach
        </select>
        @error('target_type')
        <div class="text-error">{{ $message }}</div>
        @enderror
    </div>
    <div>
        <label class="fieldset-legend mb-0.5">Priority <span class="text-error">*</span></label>
        <select name="priority" class="select w-full @error('priority') !select-error @enderror" required>
            @foreach($priorities as $priority)
                <option value="{{ $priority->value }}"
                        @selected(old('priority', $rule->priority?->value) === $priority->value)>
                    {{ $priorityLabels[$priority->value] ?? ucfirst($priority->value) }}
                </option>
            @endforeach
        </select>
        @error('priority')
        <div class="text-error">{{ $message }}</div>
        @enderror
    </div>
    <div>
        <x-toggle
            id="enabledToggle"
            label="Participate in scheduled audits"
            name="enabled"
            value="1"
            hint="Enabled rules participate in scheduled audit runs."
            @checked(old('enabled', $rule->enabled ?? true))
        />
    </div>
    <div class="md:col-span-2">
        <x-textarea
            label="Description"
            name="description"
            rows="2"
            error-field="description"
            placeholder="Optional context for admins"
        >{{ old('description', $rule->description) }}</x-textarea>
    </div>
    <div class="md:col-span-2">
        <div class="mb-2 flex items-center justify-between gap-3">
            <span class="fieldset-legend m-0">NEL Expression</span>
            <a href="{{ route('admin.nel.docs') }}" target="_blank" class="text-sm text-primary no-underline">
                <i class="o-document-text-text me-1"></i>Syntax help
            </a>
        </div>
        <x-textarea
            name="expression"
            rows="4"
            error-field="expression"
            class="font-mono"
            required
            placeholder="e.g. nation.score > 1000 && nation.soldiers < 50000"
        >{{ old('expression', $rule->expression) }}</x-textarea>
    </div>
</div>
