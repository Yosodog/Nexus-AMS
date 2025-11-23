@php
    $priorityLabels = [
        'high' => 'High (urgent)',
        'medium' => 'Medium',
        'low' => 'Low',
        'info' => 'Info only',
    ];
@endphp

<div class="row g-3">
    <div class="col-md-6">
        <label class="form-label fw-semibold">Name</label>
        <input type="text" name="name" class="form-control @error('name') is-invalid @enderror"
               value="{{ old('name', $rule->name) }}" required>
        @error('name')
        <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
    <div class="col-md-6">
        <label class="form-label fw-semibold">Target type</label>
        <select name="target_type" class="form-select @error('target_type') is-invalid @enderror" required>
            @foreach($targetTypes as $targetType)
                <option value="{{ $targetType->value }}"
                        @selected(old('target_type', $rule->target_type?->value) === $targetType->value)>
                    {{ ucfirst($targetType->value) }}
                </option>
            @endforeach
        </select>
        @error('target_type')
        <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
    <div class="col-md-6">
        <label class="form-label fw-semibold">Priority</label>
        <select name="priority" class="form-select @error('priority') is-invalid @enderror" required>
            @foreach($priorities as $priority)
                <option value="{{ $priority->value }}"
                        @selected(old('priority', $rule->priority?->value) === $priority->value)>
                    {{ $priorityLabels[$priority->value] ?? ucfirst($priority->value) }}
                </option>
            @endforeach
        </select>
        @error('priority')
        <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
    <div class="col-md-6">
        <label class="form-label fw-semibold">Enabled</label>
        <div class="form-check form-switch mt-2">
            <input class="form-check-input" type="checkbox" role="switch" id="enabledToggle"
                   name="enabled" value="1" @checked(old('enabled', $rule->enabled ?? true))>
            <label class="form-check-label" for="enabledToggle">Participate in scheduled audits</label>
        </div>
    </div>
    <div class="col-12">
        <label class="form-label fw-semibold">Description</label>
        <textarea name="description" rows="2" class="form-control @error('description') is-invalid @enderror"
                  placeholder="Optional context for admins">{{ old('description', $rule->description) }}</textarea>
        @error('description')
        <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center">
            <label class="form-label fw-semibold mb-0">NEL Expression</label>
            <a href="{{ route('admin.nel.docs') }}" target="_blank" class="small text-decoration-none">
                <i class="bi bi-journal-text me-1"></i>Syntax help
            </a>
        </div>
        <textarea name="expression" rows="4" class="form-control font-monospace @error('expression') is-invalid @enderror"
                  required placeholder="e.g. nation.score > 1000 && nation.soldiers < 50000">{{ old('expression', $rule->expression) }}</textarea>
        @error('expression')
        <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
</div>
