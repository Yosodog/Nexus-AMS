<div class="card bg-base-100 shadow">
    <div class="card-body">
        <h3 class="card-title">Nation Charts</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

            {{-- Score History --}}
            <div>
                <h4 class="font-semibold mb-2">Score History</h4>
                <canvas id="scoreChart" class="w-full h-64"></canvas>
            </div>

            {{-- Money Tax History --}}
            <div>
                <h4 class="font-semibold mb-2">Tax Revenue (Money)</h4>
                <canvas id="moneyTaxChart" class="w-full h-64"></canvas>
            </div>

            {{-- Resource Tax History --}}
            <div class="md:col-span-2">
                <h4 class="font-semibold mb-2">Resource Tax Revenue</h4>
                <canvas id="resourceTaxChart" class="w-full h-64"></canvas>
            </div>

            {{-- Net Worth --}}
            <div>
                <h4 class="font-semibold mb-2">Net Worth Over Time</h4>
                <canvas id="netWorthChart" class="w-full h-64"></canvas>
            </div>

        </div>
    </div>
</div>