@php use App\Services\PWHelperService; @endphp
<x-utils.card title="Transfer funds & resources" extraClasses="mb-2">
    <p class="text-sm text-base-content/70 mb-4">Move balances between accounts, pay down loans, or send directly to your nation.</p>
    <form method="POST" action="/accounts/transfer" class="space-y-4">
        @csrf

        <!-- From/To Selection -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="form-control">
                <label for="tran_from" class="label font-semibold">
                    <span class="label-text">From</span>
                    <span class="label-text-alt text-base-content/60" id="fromSummary">Available balance</span>
                </label>
                <select class="select select-bordered w-full" name="from" id="tran_from" required>
                    <optgroup label="Accounts">
                        @foreach ($accounts as $account)
                            <option value="{{ $account->id }}"
                                    @foreach (PWHelperService::resources() as $resource)
                                        data-{{ $resource }}="{{ $account->$resource }}"
                                    @endforeach >
                                {{ $account->name }} - ${{ number_format($account->money) }}
                            </option>
                        @endforeach
                    </optgroup>
                </select>
            </div>

            <div class="form-control">
                <label for="tran_to" class="label font-semibold">
                    <span class="label-text">To</span>
                    <span class="label-text-alt text-base-content/60">Select loan to pay only money</span>
                </label>
                <select class="select select-bordered w-full" name="to" id="tran_to" required
                        onchange="handleToSelectionChange()">
                    <optgroup label="Nation">
                        <option value="nation">Nation - {{ Auth()->user()->nation->nation_name }}</option>
                    </optgroup>
                    <optgroup label="Accounts">
                        @foreach ($accounts as $account)
                            <option value="{{ $account->id }}">{{ $account->name }} -
                                ${{ number_format($account->money) }}</option>
                        @endforeach
                    </optgroup>
                    @if (!$activeLoans->isEmpty())
                        <optgroup label="Active Loans">
                            @foreach ($activeLoans as $loan)
                                <option value="loan_{{ $loan->id }}"
                                        data-remaining-balance="{{ $loan->remaining_balance }}">Loan #{{ $loan->id }} -
                                    Balance: ${{ number_format($loan->remaining_balance, 2) }}</option>
                            @endforeach
                        </optgroup>
                    @endif
                </select>
            </div>
        </div>

        <div class="rounded-xl border border-base-300 p-4 bg-base-200/50">
            <div class="flex items-center justify-between mb-3 text-sm">
                <p class="font-semibold">Resources to move</p>
                <span class="text-base-content/70">Caps update with your selected source</span>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4" id="resource-fields">
                @foreach(PWHelperService::resources() as $resource)
                    <div class="form-control">
                        <label for="{{ $resource }}" class="label font-semibold">
                            {{ ucfirst($resource) }}
                            <span class="badge badge-ghost ml-2" id="{{ $resource }}Avail">0.00</span>
                        </label>
                        <input
                            type="number"
                            class="input input-bordered"
                            name="{{ $resource }}"
                            id="{{ $resource }}"
                            value="0"
                            step="any"
                            min="0"
                        >
                    </div>
                @endforeach
            </div>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 pt-2">
            <p class="text-sm text-base-content/70">Need a fresh deposit code? Use the Deposit button on the accounts table above.</p>
            <button type="submit" class="btn btn-primary">Transfer now</button>
        </div>
    </form>
</x-utils.card>

<script>
    function handleToSelectionChange() {
        const toSelect = document.getElementById('tran_to');
        const resourceFields = document.getElementById('resource-fields');
        const selectedValue = toSelect.value;
        const moneyInput = document.getElementById('money');
        const resourceInputs = resourceFields.querySelectorAll('input:not([name="money"])');

        if (selectedValue.startsWith('loan_')) {
            // If a loan is selected, only allow money transfers and disable all other resources
            resourceInputs.forEach(input => {
                input.value = 0;
                input.disabled = true;
            });
            moneyInput.disabled = false;

            // Get the selected loan's remaining balance
            const selectedOption = toSelect.options[toSelect.selectedIndex];
            const remainingBalance = parseFloat(selectedOption.dataset.remainingBalance);

            // Set the max attribute and title for the money input
            moneyInput.max = remainingBalance;
            moneyInput.min = 0.01; // Ensure minimum payment is at least 0.01
            moneyInput.step = 0.01; // Allow two decimal places
            moneyInput.title = `Payment amount must be between $0.01 and $${remainingBalance.toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            })}`;

            // Add event listener to enforce min/max values and prevent negative numbers
            moneyInput.addEventListener('input', function () {
                let value = parseFloat(this.value);
                if (value < 0.01) {
                    this.value = 0.01;
                } else if (value > remainingBalance) {
                    this.value = remainingBalance;
                }
            });
        } else {
            // Re-enable all resource inputs for non-loan transfers
            resourceInputs.forEach(input => {
                input.disabled = false;
            });
            moneyInput.disabled = false;

            // Remove the loan-specific attributes
            moneyInput.removeAttribute('min');
            moneyInput.removeAttribute('step');
        }

        // Reapply the from account validation after making changes
        handleFromSelectionChange();
    }

    function handleFromSelectionChange() {
        const fromSelect = document.getElementById('tran_from');
        const selectedOption = fromSelect.options[fromSelect.selectedIndex];
        const summary = document.getElementById('fromSummary');
        if (summary && selectedOption) {
            summary.textContent = selectedOption.textContent.trim();
        }

        // Get all resource values from the selected option
        const resources = {
            money: Number(selectedOption.dataset.money),
            coal: Number(selectedOption.dataset.coal),
            oil: Number(selectedOption.dataset.oil),
            uranium: Number(selectedOption.dataset.uranium),
            lead: Number(selectedOption.dataset.lead),
            iron: Number(selectedOption.dataset.iron),
            bauxite: Number(selectedOption.dataset.bauxite),
            gas: Number(selectedOption.dataset.gas),
            munitions: Number(selectedOption.dataset.munitions),
            steel: Number(selectedOption.dataset.steel),
            aluminum: Number(selectedOption.dataset.aluminum),
            food: Number(selectedOption.dataset.food)
        };

        // Update all the available resource amounts and set max values
        Object.entries(resources).forEach(([resource, amount]) => {
            // Update the display amount
            const availSpan = document.getElementById(`${resource}Avail`);
            if (availSpan) {
                availSpan.textContent = amount.toLocaleString('en-US', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });
            }

            // Update the input max value and add validation
            const input = document.getElementById(resource);
            if (input && !input.disabled) { // Only update enabled inputs
                input.max = amount;
                input.title = `Maximum available: ${amount.toLocaleString('en-US', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                })}`;

                // Remove existing event listener by cloning and replacing
                const newInput = input.cloneNode(true);
                input.parentNode.replaceChild(newInput, input);

                // Add new event listener for validation
                newInput.addEventListener('input', function () {
                    let value = Number(this.value);
                    if (value < 0) {
                        this.value = 0;
                    } else if (value > amount) {
                        this.value = amount;
                    }
                });
            }
        });
    }

    // Call the function on page load to set initial state
    document.addEventListener('DOMContentLoaded', handleFromSelectionChange);

    // Add event listener for when the from account changes
    document.getElementById('tran_from').addEventListener('change', handleFromSelectionChange);
</script>
