@extends('layouts.main')

@section('content')
    <div class="space-y-6">
        <section class="overflow-hidden rounded-lg border border-base-300 bg-base-100 shadow-md">
            <div class="grid gap-6 p-6 lg:grid-cols-[1.4fr_1fr] lg:items-center">
                <div class="space-y-3">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-primary">Weekly member lottery</p>
                    <h1 class="text-3xl font-bold text-base-content">Three characters. One weekly draw.</h1>
                    <p class="max-w-2xl text-sm text-base-content/70">
                        Every ticket costs ${{ number_format($drawing->ticket_price, 0) }} and receives a random three-character
                        uppercase alphanumeric code. {{ number_format($drawing->jackpot_basis_points / 100, 2) }}%
                        (${{ number_format($drawing->jackpot_contribution_per_ticket, 2) }}) from each ticket joins the jackpot.
                    </p>
                    <p class="max-w-2xl text-sm text-base-content/70">
                        One code is drawn from {{ number_format(\App\Services\LotteryRandomizer::CODE_SPACE_SIZE) }} possibilities.
                        If no sold ticket matches, the entire jackpot rolls into the next week.
                    </p>
                    <div class="flex flex-wrap gap-2 text-xs text-base-content/70">
                        <span class="badge badge-outline">Draws Sunday at 00:00 UTC</span>
                        <span class="badge badge-outline">Sales close {{ $drawing->ends_at->format('M j, Y \a\t H:i') }} UTC</span>
                        @if ($remainingTicketCount === 0)
                            <span class="badge badge-error badge-outline">Sold out</span>
                        @endif
                        @if ($remainingNationTicketCount === 0)
                            <span class="badge badge-warning badge-outline">Nation limit reached</span>
                        @endif
                        @if (! $drawing->sales_enabled)
                            <span class="badge badge-warning">Sales paused</span>
                        @endif
                    </div>
                </div>

                <div class="rounded-2xl border border-primary/20 bg-primary/10 p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-primary">Current jackpot</p>
                    <p class="mt-1 text-4xl font-black text-primary">${{ number_format($drawing->jackpot_amount, 2) }}</p>
                    @if ((float) $drawing->rollover_amount > 0)
                        <p class="mt-1 text-xs font-medium text-primary/80">
                            Includes ${{ number_format($drawing->rollover_amount, 2) }} rolled over
                        </p>
                    @endif
                    <div class="mt-4 grid grid-cols-3 gap-3 text-sm">
                        <div class="rounded-xl bg-base-100/80 p-3">
                            <p class="text-xs text-base-content/60">Tickets sold</p>
                            <p class="text-xl font-bold">{{ number_format($drawing->ticket_count) }}</p>
                        </div>
                        <div class="rounded-xl bg-base-100/80 p-3">
                            <p class="text-xs text-base-content/60">Remaining</p>
                            <p class="text-xl font-bold">{{ number_format($remainingTicketCount) }}</p>
                        </div>
                        <div class="rounded-xl bg-base-100/80 p-3">
                            <p class="text-xs text-base-content/60">Your tickets</p>
                            <p class="text-xl font-bold">{{ number_format($myTickets->count()) }}</p>
                        </div>
                    </div>
                    <p class="mt-3 text-xs text-base-content/60">
                        Chance any sold ticket wins:
                        {{ number_format(($drawing->ticket_count / \App\Services\LotteryRandomizer::CODE_SPACE_SIZE) * 100, 5) }}%
                    </p>
                </div>
            </div>
        </section>

        <div class="grid gap-6 lg:grid-cols-[1fr_1.25fr]">
            <section class="rounded-lg border border-base-300 bg-base-100 p-6 shadow-sm">
                <div class="mb-5">
                    <p class="text-xs uppercase tracking-wide text-base-content/60">Enter this week's draw</p>
                    <h2 class="text-xl font-bold">Buy tickets</h2>
                </div>

                @if (! $drawing->sales_enabled)
                    <div class="rounded-xl border border-warning/30 bg-warning/10 p-5 text-sm text-base-content/80">
                        <p class="font-semibold text-warning">Ticket sales are paused.</p>
                        <p class="mt-1">Existing tickets remain eligible and the drawing will still complete on schedule.</p>
                    </div>
                @elseif ($remainingTicketCount > 0 && $remainingNationTicketCount > 0)
                    <form method="POST" action="{{ route('lottery.tickets.store') }}" class="space-y-5">
                        @csrf
                        <input type="hidden" name="drawing_id" value="{{ $drawing->id }}">

                        <label class="grid gap-2">
                            <span class="text-sm font-medium">Pay from account</span>
                            <select name="account_id" class="select w-full" required>
                                <option value="" disabled @selected(! old('account_id'))>Select an account</option>
                                @foreach ($accounts as $account)
                                    <option
                                        value="{{ $account->id }}"
                                        @selected((string) old('account_id') === (string) $account->id)
                                        @disabled($account->frozen || (float) $account->money < (float) $drawing->ticket_price)
                                    >
                                        {{ $account->name }} — ${{ number_format($account->money, 2) }}{{ $account->frozen ? ' (frozen)' : '' }}
                                    </option>
                                @endforeach
                            </select>
                            @error('account_id')
                                <p class="text-xs text-error">{{ $message }}</p>
                            @enderror
                        </label>

                        <label class="grid gap-2">
                            <span class="text-sm font-medium">Number of tickets</span>
                            <input
                                type="number"
                                name="quantity"
                                class="input w-full"
                                min="1"
                                max="{{ min($drawing->max_tickets_per_purchase, $remainingTicketCount, $remainingNationTicketCount) }}"
                                step="1"
                                value="{{ old('quantity', 1) }}"
                                required
                            >
                            <span class="text-xs text-base-content/60">
                                ${{ number_format($drawing->ticket_price, 0) }} each · up to
                                {{ min($drawing->max_tickets_per_purchase, $remainingTicketCount, $remainingNationTicketCount) }} per purchase ·
                                {{ number_format($remainingNationTicketCount) }} remaining for your nation
                            </span>
                            @error('quantity')
                                <p class="text-xs text-error">{{ $message }}</p>
                            @enderror
                        </label>

                        <button type="submit" class="btn btn-primary w-full" @disabled($accounts->isEmpty())>
                            Buy lottery tickets
                        </button>

                        @if ($accounts->isEmpty())
                            <p class="text-center text-sm text-base-content/60">
                                Create an account before purchasing a ticket.
                            </p>
                        @endif
                    </form>
                @elseif ($remainingTicketCount === 0)
                    <div class="rounded-xl border border-error/30 bg-error/10 p-5 text-sm text-base-content/80">
                        <p class="font-semibold text-error">This week's drawing is sold out.</p>
                        <p class="mt-1">All {{ number_format(\App\Services\LotteryRandomizer::CODE_SPACE_SIZE) }} unique codes have been assigned.</p>
                    </div>
                @else
                    <div class="rounded-xl border border-warning/30 bg-warning/10 p-5 text-sm text-base-content/80">
                        <p class="font-semibold text-warning">Your nation has reached this drawing's ticket limit.</p>
                        <p class="mt-1">Your existing tickets remain eligible for the draw.</p>
                    </div>
                @endif
            </section>

            <section class="rounded-lg border border-base-300 bg-base-100 p-6 shadow-sm">
                <div class="mb-5 flex items-start justify-between gap-4">
                    <div>
                        <p class="text-xs uppercase tracking-wide text-base-content/60">Current drawing</p>
                        <h2 class="text-xl font-bold">Your ticket codes</h2>
                    </div>
                    @if ($myTickets->isNotEmpty())
                        <span class="badge badge-primary badge-outline">
                            {{ number_format(($myTickets->count() / \App\Services\LotteryRandomizer::CODE_SPACE_SIZE) * 100, 5) }}% chance
                        </span>
                    @endif
                </div>

                @if ($myTickets->isNotEmpty())
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-4">
                        @foreach ($myTickets as $ticket)
                            <div class="rounded-xl border border-base-300 bg-base-200/50 p-4 text-center">
                                <p class="font-mono text-2xl font-black tracking-[0.18em] text-primary">{{ $ticket->code }}</p>
                                <p class="mt-2 truncate text-xs text-base-content/60">{{ $ticket->account->name }}</p>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="rounded-xl border border-dashed border-base-300 px-6 py-12 text-center">
                        <p class="font-semibold">You do not have a ticket in this drawing yet.</p>
                        <p class="mt-1 text-sm text-base-content/60">Purchased codes will appear here immediately.</p>
                    </div>
                @endif
            </section>
        </div>

        <section class="rounded-lg border border-base-300 bg-base-100 p-6 shadow-sm">
            <div class="mb-5">
                <p class="text-xs uppercase tracking-wide text-base-content/60">Completed drawings</p>
                <h2 class="text-xl font-bold">Recent draws</h2>
            </div>

            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Week ending</th>
                            <th>Winning code</th>
                            <th>Winner</th>
                            <th class="text-right">Tickets</th>
                            <th class="text-right">Jackpot</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($recentDrawings as $pastDrawing)
                            <tr>
                                <td>{{ $pastDrawing->ends_at->format('M j, Y') }}</td>
                                <td>
                                    @if ($pastDrawing->winning_code)
                                        <span class="font-mono font-bold tracking-widest text-primary">
                                            {{ $pastDrawing->winning_code }}
                                        </span>
                                    @else
                                        <span class="text-base-content/50">—</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($pastDrawing->winningTicket)
                                        {{ $pastDrawing->winningTicket->user?->nation?->leader_name
                                            ?? $pastDrawing->winningTicket->user?->name
                                            ?? 'Unknown member' }}
                                    @elseif ((float) $pastDrawing->jackpot_amount > 0)
                                        <span class="text-warning">No match — rolled over</span>
                                    @else
                                        <span class="text-base-content/50">No match</span>
                                    @endif
                                </td>
                                <td class="text-right">{{ number_format($pastDrawing->ticket_count) }}</td>
                                <td class="text-right font-semibold">${{ number_format($pastDrawing->jackpot_amount, 2) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="py-10 text-center text-base-content/60">
                                    No weekly drawings have been completed yet.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
@endsection
