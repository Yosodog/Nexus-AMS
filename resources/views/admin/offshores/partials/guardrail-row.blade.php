@php
    $resourceValue = $guardrail['resource'] ?? '';
    $amountValue = $guardrail['minimum_amount'] ?? 0;
@endphp

<div class="row g-2 align-items-end guardrail-row mb-2">
    <div class="col-md-5">
        <label class="form-label">Resource</label>
        <select class="form-select" name="guardrails[{{ $index }}][resource]" required>
            @foreach($resources as $resource)
                <option value="{{ $resource }}" {{ $resourceValue === $resource ? 'selected' : '' }}>{{ ucfirst($resource) }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-5">
        <label class="form-label">Minimum Amount</label>
        <input type="number" step="0.01" min="0" class="form-control"
               name="guardrails[{{ $index }}][minimum_amount]"
               value="{{ $amountValue }}" required>
    </div>
    <div class="col-md-2 text-end">
        <button type="button" class="btn btn-outline-danger btn-sm" data-action="remove-guardrail">
            <i class="bi bi-trash"></i>
        </button>
    </div>
</div>
