<?php

namespace App\Services;

use App\Exceptions\UserErrorException;
use App\Models\Account;
use App\Models\LotteryDrawing;
use App\Models\LotteryTicket;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LotteryService
{
    public function __construct(
        private readonly AllianceMemberEligibilityService $eligibilityService,
        private readonly AuditLogger $auditLogger,
        private readonly LotteryRandomizer $randomizer,
    ) {}

    public function currentDrawing(?CarbonInterface $at = null): LotteryDrawing
    {
        $now = $this->utc($at);
        $startsAt = $now->startOfWeek(CarbonInterface::SUNDAY)->startOfDay();

        return $this->drawingStartingAt($startsAt);
    }

    private function drawingStartingAt(CarbonImmutable $startsAt): LotteryDrawing
    {
        $settings = SettingService::getLotterySettings();
        $jackpotContributionCents = self::jackpotContributionCents(
            $settings['ticket_price_cents'],
            $settings['jackpot_basis_points'],
        );

        return LotteryDrawing::query()->firstOrCreate(
            ['starts_at' => $startsAt],
            [
                'ends_at' => $startsAt->addWeek(),
                'status' => LotteryDrawing::STATUS_OPEN,
                'sales_enabled' => $settings['sales_enabled'],
                'ticket_price' => self::centsToDecimal($settings['ticket_price_cents']),
                'jackpot_basis_points' => $settings['jackpot_basis_points'],
                'jackpot_contribution_per_ticket' => self::centsToDecimal($jackpotContributionCents),
                'max_tickets_per_purchase' => $settings['max_tickets_per_purchase'],
                'max_tickets_per_nation' => $settings['max_tickets_per_nation'],
                'ticket_count' => 0,
                'allocation_seed' => $this->randomizer->permutationSeed(),
                'next_ticket_sequence' => 0,
                'rollover_amount' => 0,
                'jackpot_amount' => 0,
            ],
        );
    }

    /**
     * @return EloquentCollection<int, LotteryTicket>
     *
     * @throws UserErrorException
     * @throws ValidationException
     */
    public function purchaseTickets(
        User $user,
        Account $account,
        int $quantity,
        ?string $ipAddress = null,
    ): EloquentCollection {
        if ($quantity < 1 || $quantity > SettingService::MAX_LOTTERY_TICKETS_PER_PURCHASE) {
            throw ValidationException::withMessages([
                'quantity' => 'You may purchase between 1 and '.SettingService::MAX_LOTTERY_TICKETS_PER_PURCHASE.' tickets at a time.',
            ]);
        }

        $this->eligibilityService->nationFor($user);

        if ((int) $account->nation_id !== (int) $user->nation_id) {
            throw ValidationException::withMessages([
                'account_id' => 'You do not own this account.',
            ]);
        }

        $drawing = $this->currentDrawing();

        return DB::transaction(function () use ($user, $account, $quantity, $ipAddress, $drawing): EloquentCollection {
            $lockedDrawing = LotteryDrawing::query()->lockForUpdate()->findOrFail($drawing->id);

            if ($lockedDrawing->status !== LotteryDrawing::STATUS_OPEN || $lockedDrawing->ends_at->isPast()) {
                throw new UserErrorException('This lottery drawing is closed. Please refresh for the new drawing.');
            }

            if (! $lockedDrawing->sales_enabled) {
                throw new UserErrorException('Lottery ticket sales are currently paused.');
            }

            if ($quantity > $lockedDrawing->max_tickets_per_purchase) {
                throw ValidationException::withMessages([
                    'quantity' => 'You may purchase at most '.number_format($lockedDrawing->max_tickets_per_purchase).' tickets at a time.',
                ]);
            }

            $nationTicketCount = LotteryTicket::query()
                ->where('lottery_drawing_id', $lockedDrawing->id)
                ->where('nation_id', $user->nation_id)
                ->count();
            $remainingNationTickets = max(0, $lockedDrawing->max_tickets_per_nation - $nationTicketCount);

            if ($quantity > $remainingNationTickets) {
                throw ValidationException::withMessages([
                    'quantity' => $remainingNationTickets === 0
                        ? 'Your nation has reached the ticket limit for this drawing.'
                        : 'Your nation may purchase only '.number_format($remainingNationTickets).' more tickets in this drawing.',
                ]);
            }

            $remainingTickets = LotteryRandomizer::CODE_SPACE_SIZE - $lockedDrawing->next_ticket_sequence;

            if ($quantity > $remainingTickets) {
                throw ValidationException::withMessages([
                    'quantity' => $remainingTickets === 0
                        ? 'This lottery drawing is sold out.'
                        : 'Only '.number_format($remainingTickets).' tickets remain in this drawing.',
                ]);
            }

            $lockedAccount = Account::query()->lockForUpdate()->findOrFail($account->id);

            $ticketPriceCents = self::decimalToCents($lockedDrawing->ticket_price);
            $jackpotContributionPerTicketCents = self::decimalToCents($lockedDrawing->jackpot_contribution_per_ticket);
            $totalCost = self::centsToDecimal($ticketPriceCents * $quantity);
            $jackpotContribution = self::centsToDecimal($jackpotContributionPerTicketCents * $quantity);

            if ((int) $lockedAccount->nation_id !== (int) $user->nation_id) {
                throw ValidationException::withMessages([
                    'account_id' => 'You do not own this account.',
                ]);
            }

            if ($lockedAccount->frozen) {
                throw new UserErrorException('Frozen accounts cannot be used to buy lottery tickets.');
            }

            if ((float) $lockedAccount->money < $totalCost) {
                throw ValidationException::withMessages([
                    'account_id' => 'The selected account does not have enough money for this purchase.',
                ]);
            }

            $codes = $this->randomizer->ticketCodesForRange(
                $lockedDrawing->allocation_seed,
                $lockedDrawing->next_ticket_sequence,
                $quantity,
            );
            $timestamp = now();
            $ticketRows = collect($codes)
                ->map(fn (string $code): array => [
                    'lottery_drawing_id' => $lockedDrawing->id,
                    'user_id' => $user->id,
                    'nation_id' => $user->nation_id,
                    'account_id' => $lockedAccount->id,
                    'code' => $code,
                    'price_paid' => $lockedDrawing->ticket_price,
                    'jackpot_contribution' => $lockedDrawing->jackpot_contribution_per_ticket,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ])
                ->all();

            LotteryTicket::query()->insert($ticketRows);

            $ticketsByCode = LotteryTicket::query()
                ->where('lottery_drawing_id', $lockedDrawing->id)
                ->whereIn('code', $codes)
                ->get()
                ->keyBy('code');
            $tickets = new EloquentCollection(
                collect($codes)->map(fn (string $code): LotteryTicket => $ticketsByCode->get($code))->all(),
            );

            AccountService::adjustAccountBalance(
                $lockedAccount,
                [
                    'money' => -$totalCost,
                    'note' => 'Weekly lottery ticket purchase',
                ],
                $user->id,
                $ipAddress,
                [
                    'lottery_drawing_id' => $lockedDrawing->id,
                    'nation_id' => $user->nation_id,
                    'lottery_ticket_ids' => $tickets->modelKeys(),
                    'ticket_quantity' => $quantity,
                    'jackpot_contribution' => $jackpotContribution,
                    'amount_excluded_from_jackpot' => $totalCost - $jackpotContribution,
                ],
            );

            $lockedDrawing->ticket_count += $quantity;
            $lockedDrawing->next_ticket_sequence += $quantity;
            $lockedDrawing->jackpot_amount = (float) $lockedDrawing->jackpot_amount + $jackpotContribution;
            $lockedDrawing->save();

            $this->auditLogger->record(
                category: 'lottery',
                action: 'tickets_purchased',
                subject: $lockedDrawing,
                context: [
                    'account_id' => $lockedAccount->id,
                    'nation_id' => $user->nation_id,
                    'ticket_ids' => $tickets->modelKeys(),
                    'quantity' => $quantity,
                    'total_cost' => $totalCost,
                    'jackpot_contribution' => $jackpotContribution,
                    'amount_excluded_from_jackpot' => $totalCost - $jackpotContribution,
                ],
            );

            return $tickets;
        }, attempts: 3);
    }

    public static function jackpotContributionCents(int $ticketPriceCents, int $jackpotBasisPoints): int
    {
        return intdiv(($ticketPriceCents * $jackpotBasisPoints) + 5000, 10000);
    }

    private static function decimalToCents(string|int|float $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    private static function centsToDecimal(int $amount): string
    {
        return number_format($amount / 100, 2, '.', '');
    }

    /**
     * @return EloquentCollection<int, LotteryDrawing>
     */
    public function drawExpiredDrawings(?CarbonInterface $at = null): EloquentCollection
    {
        $drawnAt = $this->utc($at);
        $drawings = new EloquentCollection;

        while (true) {
            $drawingId = LotteryDrawing::query()
                ->where('status', LotteryDrawing::STATUS_OPEN)
                ->where('ends_at', '<=', $drawnAt)
                ->orderBy('ends_at')
                ->value('id');

            if (! $drawingId) {
                break;
            }

            $drawing = LotteryDrawing::query()->find($drawingId);

            if ($drawing) {
                $drawings->push($this->draw($drawing, $drawnAt));
            }
        }

        return $drawings;
    }

    /**
     * @throws UserErrorException
     */
    public function draw(LotteryDrawing $drawing, ?CarbonInterface $at = null): LotteryDrawing
    {
        $drawnAt = $this->utc($at);

        $drawnDrawing = DB::transaction(function () use ($drawing, $drawnAt): LotteryDrawing {
            $lockedDrawing = LotteryDrawing::query()->lockForUpdate()->findOrFail($drawing->id);

            if ($lockedDrawing->status === LotteryDrawing::STATUS_DRAWN) {
                return $lockedDrawing;
            }

            if ($lockedDrawing->ends_at->isAfter($drawnAt)) {
                throw new UserErrorException('This lottery drawing has not closed yet.');
            }

            $ticketAggregate = LotteryTicket::query()
                ->where('lottery_drawing_id', $lockedDrawing->id)
                ->selectRaw('COUNT(*) as ticket_count, COALESCE(SUM(jackpot_contribution), 0) as jackpot_contribution')
                ->firstOrFail();

            $lockedDrawing->ticket_count = (int) $ticketAggregate->ticket_count;
            $lockedDrawing->jackpot_amount = (float) $lockedDrawing->rollover_amount
                + (float) $ticketAggregate->jackpot_contribution;
            $lockedDrawing->status = LotteryDrawing::STATUS_DRAWN;
            $lockedDrawing->drawn_at = $drawnAt;
            $lockedDrawing->winning_code = $this->randomizer->ticketCode();
            $winner = LotteryTicket::query()
                ->where('lottery_drawing_id', $lockedDrawing->id)
                ->where('code', $lockedDrawing->winning_code)
                ->first();
            $rolloverDrawingId = null;

            if ($winner) {
                $winnerAccount = Account::query()->lockForUpdate()->findOrFail($winner->account_id);

                AccountService::adjustAccountBalance(
                    $winnerAccount,
                    [
                        'money' => (float) $lockedDrawing->jackpot_amount,
                        'note' => 'Weekly lottery jackpot payout',
                    ],
                    null,
                    null,
                    [
                        'lottery_drawing_id' => $lockedDrawing->id,
                        'lottery_ticket_id' => $winner->id,
                        'lottery_ticket_code' => $winner->code,
                    ],
                );

                $lockedDrawing->winning_ticket_id = $winner->id;
            } elseif ((float) $lockedDrawing->jackpot_amount > 0) {
                $nextDrawing = $this->drawingStartingAt($lockedDrawing->ends_at);
                $lockedNextDrawing = LotteryDrawing::query()->lockForUpdate()->findOrFail($nextDrawing->id);

                if ($lockedNextDrawing->status !== LotteryDrawing::STATUS_OPEN) {
                    throw new \RuntimeException('The rollover destination drawing is already closed.');
                }

                $lockedNextDrawing->rollover_amount = (float) $lockedNextDrawing->rollover_amount
                    + (float) $lockedDrawing->jackpot_amount;
                $lockedNextDrawing->jackpot_amount = (float) $lockedNextDrawing->jackpot_amount
                    + (float) $lockedDrawing->jackpot_amount;
                $lockedNextDrawing->save();
                $rolloverDrawingId = $lockedNextDrawing->id;
            }

            $lockedDrawing->save();

            $this->auditLogger->record(
                category: 'lottery',
                action: 'drawing_completed',
                subject: $lockedDrawing,
                context: [
                    'ticket_count' => $lockedDrawing->ticket_count,
                    'jackpot_amount' => $lockedDrawing->jackpot_amount,
                    'winning_code' => $lockedDrawing->winning_code,
                    'winning_ticket_id' => $lockedDrawing->winning_ticket_id,
                    'rollover_drawing_id' => $rolloverDrawingId,
                    'rolled_over_amount' => $rolloverDrawingId ? $lockedDrawing->jackpot_amount : 0,
                ],
                actorOverride: ['type' => 'system', 'id' => null, 'name' => 'Lottery scheduler'],
            );

            return $lockedDrawing;
        }, attempts: 3);

        return $drawnDrawing->fresh(['winningTicket.user.nation', 'winningTicket.account']) ?? $drawnDrawing;
    }

    private function utc(?CarbonInterface $at): CarbonImmutable
    {
        return $at
            ? CarbonImmutable::instance($at)->utc()
            : CarbonImmutable::now('UTC');
    }
}
