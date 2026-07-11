<div class="grid grid-cols-2 gap-3">
    <label class="grid gap-2">
        <span class="text-sm font-medium">Cooldown</span>
        <select name="cooldown_minutes" class="select w-full">
            @foreach([15 => '15 minutes', 30 => '30 minutes', 60 => '1 hour', 360 => '6 hours', 1440 => '1 day'] as $minutes => $label)
                <option value="{{ $minutes }}" @selected(old('type') === $formType && (int) old('cooldown_minutes', 60) === $minutes)>{{ $label }}</option>
            @endforeach
        </select>
    </label>
    <label class="grid gap-2">
        <span class="text-sm font-medium">Expires <span class="text-base-content/50">(optional)</span></span>
        <input type="datetime-local" name="expires_at" value="{{ old('type') === $formType ? old('expires_at') : '' }}" class="input w-full">
    </label>
</div>
