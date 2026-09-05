<fieldset class="space-y-3">
    <legend class="font-semibold">Tax Programs <span class="text-sm font-normal nexus-text-muted">(optional)</span></legend>
    <p class="text-sm nexus-text-muted">
        Configure both IDs for each program you want members of this offshore alliance to use. These brackets must exist in this alliance's in-game tax bracket list.
    </p>

    <div class="grid gap-4 md:grid-cols-2">
        @foreach([
            'direct_deposit_tax_id' => 'Direct Deposit Tax ID',
            'direct_deposit_fallback_tax_id' => 'Direct Deposit Fallback Tax ID',
            'growth_circles_tax_id' => 'Growth Circles Tax ID',
            'growth_circles_fallback_tax_id' => 'Growth Circles Fallback Tax ID',
        ] as $field => $label)
            @php $inputId = $prefix.'-'.$field; @endphp
            <label class="block space-y-2" for="{{ $inputId }}">
                <span class="text-sm font-medium">{{ $label }}</span>
                <input
                    id="{{ $inputId }}"
                    type="number"
                    class="input w-full"
                    name="{{ $field }}"
                    value="{{ $values[$field] ?? '' }}"
                    min="1"
                    aria-invalid="{{ $showErrors && $errors->has($field) ? 'true' : 'false' }}"
                    @if($showErrors && $errors->has($field)) aria-describedby="{{ $inputId }}-error" @endif
                >
                @if($showErrors && $errors->has($field))
                    <span id="{{ $inputId }}-error" class="text-xs text-error">{{ $errors->first($field) }}</span>
                @endif
            </label>
        @endforeach
    </div>
</fieldset>
