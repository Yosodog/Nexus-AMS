<div class="grid grid-cols-2 gap-3">
    <div class="grid gap-2">
        <label class="text-sm font-medium" for="{{ $formType }}-cooldown-minutes">Cooldown</label>
        <select id="{{ $formType }}-cooldown-minutes" name="cooldown_minutes" class="select w-full"
            @if(old('type') === $formType && $errors->has('cooldown_minutes')) aria-describedby="{{ $formType }}-cooldown-minutes-error" aria-invalid="true" @endif>
            @foreach([15 => '15 minutes', 30 => '30 minutes', 60 => '1 hour', 360 => '6 hours', 1440 => '1 day'] as $minutes => $label)
                <option value="{{ $minutes }}" @selected(old('type') === $formType && (int) old('cooldown_minutes', 60) === $minutes)>{{ $label }}</option>
            @endforeach
        </select>
        @if(old('type') === $formType)
            @error('cooldown_minutes')
                <p id="{{ $formType }}-cooldown-minutes-error" class="text-sm text-error">{{ $message }}</p>
            @enderror
        @endif
    </div>
    <div class="grid gap-2">
        <label class="text-sm font-medium" for="{{ $formType }}-expires-at">Expires <span class="nexus-text-muted">(optional)</span></label>
        <input id="{{ $formType }}-expires-at" type="datetime-local" name="expires_at" value="{{ old('type') === $formType ? old('expires_at') : '' }}" class="input w-full"
            @if(old('type') === $formType && $errors->has('expires_at')) aria-describedby="{{ $formType }}-expires-at-error" aria-invalid="true" @endif>
        @if(old('type') === $formType)
            @error('expires_at')
                <p id="{{ $formType }}-expires-at-error" class="text-sm text-error">{{ $message }}</p>
            @enderror
        @endif
    </div>
</div>
