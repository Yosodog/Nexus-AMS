@extends('layouts.admin')

@section('content')
    <x-header title="Weekly Lottery" separator use-h1>
        <x-slot:subtitle>Monitor the active drawing and control ticket economics without changing terms already sold.</x-slot:subtitle>
    </x-header>

    <div class="grid gap-6 xl:grid-cols-[1.05fr_1.35fr]">
        <x-card title="Current drawing" subtitle="Live status and locked economics for the active sales window.">
            @if ($currentDrawing)
                <x-slot:menu>
                    <span class="badge {{ $currentDrawing->sales_enabled ? 'badge-success' : 'badge-warning' }}">
                        {{ $currentDrawing->sales_enabled ? 'Sales enabled' : 'Sales paused' }}
                    </span>
                </x-slot:menu>

                <dl class="grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-base-content/60">Drawing window</dt>
                        <dd class="mt-1 font-semibold">
                            {{ $currentDrawing->starts_at->format('M j, H:i') }}–{{ $currentDrawing->ends_at->format('M j, Y H:i') }} UTC
                        </dd>
                    </div>
                    <div>
                        <dt class="text-base-content/60">Current jackpot</dt>
                        <dd class="mt-1 font-semibold">${{ number_format($currentDrawing->jackpot_amount, 2) }}</dd>
                    </div>
                    <div>
                        <dt class="text-base-content/60">Tickets sold</dt>
                        <dd class="mt-1 font-semibold">{{ number_format($currentDrawing->ticket_count) }}</dd>
                    </div>
                    <div>
                        <dt class="text-base-content/60">Rollover included</dt>
                        <dd class="mt-1 font-semibold">${{ number_format($currentDrawing->rollover_amount, 2) }}</dd>
                    </div>
                    <div>
                        <dt class="text-base-content/60">Ticket price</dt>
                        <dd class="mt-1 font-semibold">${{ number_format($currentDrawing->ticket_price, 2) }}</dd>
                    </div>
                    <div>
                        <dt class="text-base-content/60">Jackpot contribution</dt>
                        <dd class="mt-1 font-semibold">
                            {{ number_format($currentDrawing->jackpot_basis_points / 100, 2) }}%
                            · ${{ number_format($currentDrawing->jackpot_contribution_per_ticket, 2) }} per ticket
                        </dd>
                    </div>
                    <div>
                        <dt class="text-base-content/60">Per purchase</dt>
                        <dd class="mt-1 font-semibold">{{ number_format($currentDrawing->max_tickets_per_purchase) }} tickets</dd>
                    </div>
                    <div>
                        <dt class="text-base-content/60">Per nation</dt>
                        <dd class="mt-1 font-semibold">
                            {{ number_format($currentDrawing->max_tickets_per_nation) }} tickets
                            · ${{ number_format((float) $currentDrawing->ticket_price * $currentDrawing->max_tickets_per_nation, 0) }} maximum spend
                        </dd>
                    </div>
                </dl>
            @else
                <div class="rounded-box border border-dashed border-base-300 px-6 py-10 text-center">
                    <p class="font-semibold">No drawing has been created for the current week.</p>
                    <p class="mt-1 text-sm text-base-content/60">The saved configuration will be snapshotted when the first member opens the lottery.</p>
                </div>
            @endif
        </x-card>

        <x-card title="Lottery configuration" subtitle="Guardrails apply immediately; economic changes start with the next drawing created.">
            <x-slot:menu>
                <span class="badge badge-outline">{{ $canManageLottery ? 'Editable' : 'View only' }}</span>
            </x-slot:menu>

            @if ($canManageLottery)
                <form method="POST" action="{{ route('admin.lottery.settings.update') }}" class="space-y-5">
                    @csrf

                    <input type="hidden" name="lottery_sales_enabled" value="0">
                    <label class="label cursor-pointer justify-start gap-3 rounded-box border border-base-300 px-4 py-3">
                        <input
                            class="toggle toggle-primary"
                            type="checkbox"
                            name="lottery_sales_enabled"
                            value="1"
                            @checked(old('lottery_sales_enabled', $settings['sales_enabled']))
                        >
                        <span>
                            <span class="block font-semibold">Enable ticket sales</span>
                            <span class="block text-xs text-base-content/60">Pausing sales never cancels tickets or the scheduled draw.</span>
                        </span>
                    </label>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="grid gap-2">
                            <span class="text-sm font-medium">Ticket price</span>
                            <div class="join w-full">
                                <span class="join-item flex items-center border border-base-300 bg-base-200 px-3" aria-hidden="true">$</span>
                                <input
                                    class="input join-item w-full"
                                    type="number"
                                    name="ticket_price"
                                    min="1"
                                    max="1000000000"
                                    step="1"
                                    value="{{ old('ticket_price', (int) $nextTicketPrice) }}"
                                    required
                                >
                            </div>
                            <span class="text-xs text-base-content/60">Effective with the next drawing.</span>
                            @error('ticket_price')<span class="text-xs text-error">{{ $message }}</span>@enderror
                        </label>

                        <label class="grid gap-2">
                            <span class="text-sm font-medium">Jackpot share</span>
                            <div class="join w-full">
                                <input
                                    class="input join-item w-full"
                                    type="number"
                                    name="jackpot_percentage"
                                    min="0"
                                    max="100"
                                    step="0.01"
                                    value="{{ old('jackpot_percentage', number_format($nextJackpotPercentage, 2, '.', '')) }}"
                                    required
                                >
                                <span class="join-item flex items-center border border-base-300 bg-base-200 px-3" aria-hidden="true">%</span>
                            </div>
                            <span class="text-xs text-base-content/60">
                                Currently previews ${{ number_format($nextJackpotContribution, 2) }} per ticket for the next drawing.
                            </span>
                            @error('jackpot_percentage')<span class="text-xs text-error">{{ $message }}</span>@enderror
                        </label>

                        <label class="grid gap-2">
                            <span class="text-sm font-medium">Maximum per purchase</span>
                            <input
                                class="input w-full"
                                type="number"
                                name="max_tickets_per_purchase"
                                min="1"
                                max="500"
                                step="1"
                                value="{{ old('max_tickets_per_purchase', $settings['max_tickets_per_purchase']) }}"
                                required
                            >
                            <span class="text-xs text-base-content/60">Applies to the active drawing immediately; hard ceiling 500.</span>
                            @error('max_tickets_per_purchase')<span class="text-xs text-error">{{ $message }}</span>@enderror
                        </label>

                        <label class="grid gap-2">
                            <span class="text-sm font-medium">Maximum per nation per drawing</span>
                            <input
                                class="input w-full"
                                type="number"
                                name="max_tickets_per_nation"
                                min="1"
                                max="10000"
                                step="1"
                                value="{{ old('max_tickets_per_nation', $settings['max_tickets_per_nation']) }}"
                                required
                            >
                            <span class="text-xs text-base-content/60">
                                Applies immediately. At the next ticket price, the configured maximum spend is ${{ number_format($nextNationSpendLimit, 0) }}.
                            </span>
                            @error('max_tickets_per_nation')<span class="text-xs text-error">{{ $message }}</span>@enderror
                        </label>
                    </div>

                    <div class="alert alert-info text-sm">
                        Existing tickets keep their original price and jackpot contribution. Lowering a limit never removes tickets already sold; it only blocks additional purchases above the new limit.
                    </div>

                    <div class="flex justify-end">
                        <button type="submit" class="btn btn-primary">Save lottery configuration</button>
                    </div>
                </form>
            @else
                <dl class="grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
                    <div><dt class="text-base-content/60">Sales default</dt><dd class="mt-1 font-semibold">{{ $settings['sales_enabled'] ? 'Enabled' : 'Paused' }}</dd></div>
                    <div><dt class="text-base-content/60">Next ticket price</dt><dd class="mt-1 font-semibold">${{ number_format($nextTicketPrice, 2) }}</dd></div>
                    <div><dt class="text-base-content/60">Next jackpot share</dt><dd class="mt-1 font-semibold">{{ number_format($nextJackpotPercentage, 2) }}%</dd></div>
                    <div><dt class="text-base-content/60">Next contribution</dt><dd class="mt-1 font-semibold">${{ number_format($nextJackpotContribution, 2) }}</dd></div>
                    <div><dt class="text-base-content/60">Per purchase</dt><dd class="mt-1 font-semibold">{{ number_format($settings['max_tickets_per_purchase']) }}</dd></div>
                    <div><dt class="text-base-content/60">Per nation</dt><dd class="mt-1 font-semibold">{{ number_format($settings['max_tickets_per_nation']) }}</dd></div>
                </dl>
                <p class="mt-5 text-sm text-base-content/60">The Manage Lottery permission is required to change these values.</p>
            @endif
        </x-card>
    </div>
@endsection
