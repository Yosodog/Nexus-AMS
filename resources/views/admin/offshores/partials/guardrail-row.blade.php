@php
    $resourceValue = $guardrail['resource'] ?? '';
    $amountValue = $guardrail['minimum_amount'] ?? 0;
@endphp

<div class="guardrail-row grid gap-3 rounded-box border border-base-300 p-3 md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] md:items-end">
    <label class="block space-y-2">
        <span class="text-sm font-medium">Resource</span>
        <select class="select select-bordered w-full" name="guardrails[{{ $index }}][resource]" required>
            @foreach($resources as $resource)
                <option value="{{ $resource }}" {{ $resourceValue === $resource ? 'selected' : '' }}>{{ ucfirst($resource) }}</option>
            @endforeach
        </select>
    </label>

    <label class="block space-y-2">
        <span class="text-sm font-medium">Minimum Amount</span>
        <input
            type="number"
            step="0.01"
            min="0"
            class="input input-bordered w-full"
            name="guardrails[{{ $index }}][minimum_amount]"
            value="{{ $amountValue }}"
            required
        >
    </label>

    <button type="button" class="btn btn-outline btn-error btn-sm md:self-end" data-action="remove-guardrail">
        <x-icon name="o-trash" class="size-4" />
    </button>
</div>
