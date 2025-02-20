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
                            <option value="{{ $account->id }}">{{ $account->name }} - ${{ number_format($account->money) }}</option>
                        @endforeach
                    </optgroup>
                </select>
            </div>

            <!-- To Account Selection -->
            <div class="form-control">
                <label for="tran_to" class="label font-semibold">To</label>
                <select class="select select-bordered w-full" name="to" id="tran_to" required>
                    <optgroup label="Nation">
                        <option value="nation">Nation - {{ Auth()->user()->nation->nation_name }}</option>
                    </optgroup>
                    <optgroup label="Accounts">
                        @foreach ($accounts as $account)
                            <option value="{{ $account->id }}">{{ $account->name }} - ${{ number_format($account->money) }}</option>
                        @endforeach
                    </optgroup>
                </select>
            </div>
        </div>

        <!-- Resource Fields -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
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
                <input type="number" class="input input-bordered" name="uranium" id="uranium" value="0" step="any" min="0">
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
                <input type="number" class="input input-bordered" name="bauxite" id="bauxite" value="0" step="any" min="0">
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
                <input type="number" class="input input-bordered" name="munitions" id="munitions" value="0" step="any" min="0">
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
                <input type="number" class="input input-bordered" name="aluminum" id="aluminum" value="0" step="any" min="0">
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
