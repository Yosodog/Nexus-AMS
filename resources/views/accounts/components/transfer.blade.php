<x-utils.card title="Transfer" extraClasses="mb-2">
    <form method="POST" action="/accounts/transfer">
        @csrf <!-- Include CSRF token if using Laravel -->

        <!-- From Account Selection -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
            <div class="form-control">
                <label for="tran_from" class="label font-semibold">From</label>
                <select class="select select-bordered w-full" name="from" id="tran_from" required>
                    <optgroup label="Accounts">
                        @foreach ($accounts as $account)
                            <option value="{{ $account->id }}" 
                                data-money="{{ $account->money }}"
                                data-coal="{{ $account->coal }}"
                                data-oil="{{ $account->oil }}"
                                data-uranium="{{ $account->uranium }}"
                                data-lead="{{ $account->lead }}"
                                data-iron="{{ $account->iron }}"
                                data-bauxite="{{ $account->bauxite }}"
                                data-gas="{{ $account->gasoline }}"
                                data-munitions="{{ $account->munitions }}"
                                data-steel="{{ $account->steel }}"
                                data-aluminum="{{ $account->aluminum }}"
                                data-food="{{ $account->food }}">
                                {{ $account->name }} - ${{ number_format($account->money) }}
                            </option>
                        @endforeach
                    </optgroup>
                </select>
            </div>

            <!-- To Account Selection -->
            <div class="form-control">
                <label for="tran_to" class="label font-semibold">To</label>
                <select class="select select-bordered w-full" name="to" id="tran_to" required onchange="handleToSelectionChange()">
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
                                <option value="loan_{{ $loan->id }}" data-remaining-balance="{{ $loan->remaining_balance }}">Loan #{{ $loan->id }} - Balance: ${{ number_format($loan->remaining_balance, 2) }}</option>
                            @endforeach
                        </optgroup>
                    @endif
                </select>
            </div>
        </div>

        <!-- Resource Fields -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4" id="resource-fields">
            <!-- Money Field -->
            <div class="form-control">
                <label for="money" class="label font-semibold">
                    Money <span class="badge badge-info ml-2">$<span id="moneyAvail">0.00</span></span>
                </label>
                <input type="number" class="input input-bordered" name="money" id="money" value="0" step="any" min="0">
            </div>

            <!-- Coal Field -->
            <div class="form-control">
                <label for="coal" class="label font-semibold">
                    Coal <span class="badge badge-info ml-2"><span id="coalAvail">0.00</span></span>
                </label>
                <input type="number" class="input input-bordered" name="coal" id="coal" value="0" step="any" min="0">
            </div>

            <!-- Oil Field -->
            <div class="form-control">
                <label for="oil" class="label font-semibold">
                    Oil <span class="badge badge-info ml-2"><span id="oilAvail">0.00</span></span>
                </label>
                <input type="number" class="input input-bordered" name="oil" id="oil" value="0" step="any" min="0">
            </div>

            <!-- Uranium Field -->
            <div class="form-control">
                <label for="uranium" class="label font-semibold">
                    Uranium <span class="badge badge-info ml-2"><span id="uraniumAvail">0.00</span></span>
                </label>
                <input type="number" class="input input-bordered" name="uranium" id="uranium" value="0" step="any"
                       min="0">
            </div>

            <!-- Lead Field -->
            <div class="form-control">
                <label for="lead" class="label font-semibold">
                    Lead <span class="badge badge-info ml-2"><span id="leadAvail">0.00</span></span>
                </label>
                <input type="number" class="input input-bordered" name="lead" id="lead" value="0" step="any" min="0">
            </div>

            <!-- Iron Field -->
            <div class="form-control">
                <label for="iron" class="label font-semibold">
                    Iron <span class="badge badge-info ml-2"><span id="ironAvail">0.00</span></span>
                </label>
                <input type="number" class="input input-bordered" name="iron" id="iron" value="0" step="any" min="0">
            </div>

            <!-- Bauxite Field -->
            <div class="form-control">
                <label for="bauxite" class="label font-semibold">
                    Bauxite <span class="badge badge-info ml-2"><span id="bauxiteAvail">0.00</span></span>
                </label>
                <input type="number" class="input input-bordered" name="bauxite" id="bauxite" value="0" step="any"
                       min="0">
            </div>

            <!-- Gas Field -->
            <div class="form-control">
                <label for="gas" class="label font-semibold">
                    Gas <span class="badge badge-info ml-2"><span id="gasAvail">0.00</span></span>
                </label>
                <input type="number" class="input input-bordered" name="gas" id="gas" value="0" step="any" min="0">
            </div>

            <!-- Munitions Field -->
            <div class="form-control">
                <label for="munitions" class="label font-semibold">
                    Munitions <span class="badge badge-info ml-2"><span id="munitionsAvail">0.00</span></span>
                </label>
                <input type="number" class="input input-bordered" name="munitions" id="munitions" value="0" step="any"
                       min="0">
            </div>

            <!-- Steel Field -->
            <div class="form-control">
                <label for="steel" class="label font-semibold">
                    Steel <span class="badge badge-info ml-2"><span id="steelAvail">0.00</span></span>
                </label>
                <input type="number" class="input input-bordered" name="steel" id="steel" value="0" step="any" min="0">
            </div>

            <!-- Aluminum Field -->
            <div class="form-control">
                <label for="aluminum" class="label font-semibold">
                    Aluminum <span class="badge badge-info ml-2"><span id="aluminumAvail">0.00</span></span>
                </label>
                <input type="number" class="input input-bordered" name="aluminum" id="aluminum" value="0" step="any"
                       min="0">
            </div>

            <!-- Food Field -->
            <div class="form-control">
                <label for="food" class="label font-semibold">
                    Food <span class="badge badge-info ml-2"><span id="foodAvail">0.00</span></span>
                </label>
                <input type="number" class="input input-bordered" name="food" id="food" value="0" step="any" min="0">
            </div>
        </div>

        <!-- Submit Button -->
        <div class="mt-6">
            <button type="submit" class="btn btn-primary btn-block">Transfer</button>
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
            moneyInput.title = `Payment amount must be between $0.01 and $${remainingBalance.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
            
            // Add event listener to enforce min/max values and prevent negative numbers
            moneyInput.addEventListener('input', function() {
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
                availSpan.textContent = amount.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            }

            // Update the input max value and add validation
            const input = document.getElementById(resource);
            if (input && !input.disabled) { // Only update enabled inputs
                input.max = amount;
                input.title = `Maximum available: ${amount.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
                
                // Remove existing event listener by cloning and replacing
                const newInput = input.cloneNode(true);
                input.parentNode.replaceChild(newInput, input);
                
                // Add new event listener for validation
                newInput.addEventListener('input', function() {
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
