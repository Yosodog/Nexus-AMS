@php use App\Services\PWHelperService; @endphp
<x-utils.card title="" extraClasses="mb-2">
    <div x-data="memberTransferSearch()">
    <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between mb-4">
        <div>
            <h2 class="card-title">Transfer funds & resources</h2>
            <p class="text-sm text-base-content/70">Move balances between accounts, pay down loans, or send directly to your nation.</p>
        </div>
        <div class="join w-full md:w-auto">
            <button type="button"
                    class="btn join-item flex-1 md:flex-none"
                    :class="destinationMode === 'standard' ? 'btn-primary' : 'btn-outline'"
                    @click="switchToStandard()">
                Standard destinations
            </button>
            <button type="button"
                    class="btn join-item flex-1 md:flex-none"
                    :class="destinationMode === 'alliance' ? 'btn-primary' : 'btn-outline'"
                    @click="switchToAlliance()">
                Transfer to Alliance Member
            </button>
        </div>
    </div>
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
                <div class="space-y-3">
                    <select class="select select-bordered w-full h-12"
                            :class="destinationMode === 'alliance' ? 'hidden' : ''"
                            name="to"
                            required
                            id="tran_to"
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
                    <input type="hidden" id="alliance_to" name="" value="">
                    <div class="rounded-xl border border-base-300 bg-base-200/60 p-3"
                         x-show="destinationMode === 'alliance'" x-cloak>
                        <label class="label font-semibold">
                            <span class="label-text">Send to alliance member</span>
                            <span class="label-text-alt text-base-content/60">Search by nation or leader</span>
                        </label>
                        <div class="grid grid-cols-1 md:grid-cols-[1fr_auto] gap-3 items-center">
                            <input
                                type="text"
                                class="input input-bordered w-full h-12"
                                placeholder="Start typing a nation or leader name"
                                x-model="query"
                                @input.debounce.350ms="search"
                                @keydown.escape="clearResults"
                            >
                            <button type="button" class="btn btn-outline h-12 px-6" @click="search" :disabled="isLoading || query.length < 2">
                                <span x-show="!isLoading">Search</span>
                                <span x-show="isLoading">Searching…</span>
                            </button>
                        </div>
                        <p class="mt-2 text-xs text-base-content/60">We only show alliance members and their account names—no balances.</p>

                        <div class="mt-3 space-y-2" x-show="results.length > 0">
                            <template x-for="result in results" :key="result.nation_id">
                                <div class="rounded-lg border border-base-300 bg-base-100 p-3">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <div>
                                            <p class="text-sm font-semibold" x-text="result.nation_name"></p>
                                            <p class="text-xs text-base-content/60" x-text="`Leader: ${result.leader_name}`"></p>
                                        </div>
                                        <span class="badge badge-outline">Accounts</span>
                                    </div>
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        <template x-for="account in result.accounts" :key="account.id">
                                            <button
                                                type="button"
                                                class="btn btn-xs btn-ghost border border-base-300"
                                                @click="selectAccount(account.id, result.nation_name, account.name)"
                                                x-text="account.name"
                                            ></button>
                                        </template>
                                    </div>
                                </div>
                            </template>
                        </div>

                        <div class="mt-3 rounded-lg border border-base-300 bg-base-100 p-3 text-sm" x-show="selectedAccount">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <p class="font-semibold">Selected alliance recipient</p>
                                    <p class="text-xs text-base-content/60" x-text="selectedLabel"></p>
                                </div>
                                <button type="button" class="btn btn-xs btn-outline" @click="clearSelection">Clear</button>
                            </div>
                        </div>
                    </div>
                </div>
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
    </div>
</x-utils.card>

<script>
    function memberTransferSearch() {
        return {
            query: '',
            results: [],
            selectedAccount: null,
            selectedLabel: '',
            isLoading: false,
            destinationMode: 'standard',
            async search() {
                const query = this.query.trim();
                if (query.length < 2) {
                    this.results = [];
                    return;
                }

                this.isLoading = true;

                try {
                    const response = await fetch(`/accounts/member-transfer-search?q=${encodeURIComponent(query)}`);
                    if (!response.ok) {
                        this.results = [];
                        return;
                    }

                    const payload = await response.json();
                    this.results = Array.isArray(payload.results) ? payload.results : [];
                } catch (error) {
                    this.results = [];
                } finally {
                    this.isLoading = false;
                }
            },
            selectAccount(id, nationName, accountName) {
                const toSelect = document.getElementById('tran_to');
                const allianceInput = document.getElementById('alliance_to');

                if (toSelect) {
                    toSelect.value = id;
                    handleToSelectionChange();
                }

                if (allianceInput) {
                    allianceInput.value = id;
                }

                this.selectedAccount = id;
                this.selectedLabel = `${nationName} — ${accountName}`;
                this.results = [];
            },
            clearSelection() {
                this.selectedAccount = null;
                this.selectedLabel = '';
                const toSelect = document.getElementById('tran_to');
                const allianceInput = document.getElementById('alliance_to');
                if (toSelect) {
                    toSelect.value = 'nation';
                    handleToSelectionChange();
                }
                if (allianceInput) {
                    allianceInput.value = '';
                }
            },
            clearResults() {
                this.results = [];
            },
            switchToAlliance() {
                this.destinationMode = 'alliance';
                const toSelect = document.getElementById('tran_to');
                const allianceInput = document.getElementById('alliance_to');
                if (toSelect) {
                    toSelect.value = '';
                    toSelect.disabled = true;
                    toSelect.required = false;
                }
                if (allianceInput) {
                    allianceInput.name = 'to';
                    allianceInput.value = '';
                }
                this.selectedAccount = null;
                this.selectedLabel = '';
                this.results = [];
            },
            switchToStandard() {
                this.destinationMode = 'standard';
                const toSelect = document.getElementById('tran_to');
                const allianceInput = document.getElementById('alliance_to');
                if (toSelect) {
                    toSelect.disabled = false;
                    toSelect.required = true;
                }
                if (allianceInput) {
                    allianceInput.name = '';
                    allianceInput.value = '';
                }
                this.clearSelection();
            },
        };
    }

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
