<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateLotterySettingsRequest;
use App\Models\LotteryDrawing;
use App\Services\AuditLogger;
use App\Services\LotteryService;
use App\Services\SettingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class LotteryController extends Controller
{
    public function index(): View
    {
        $user = request()->user();
        abort_unless($user?->canAny(['view-lottery', 'manage-lottery']), 403);

        $settings = SettingService::getLotterySettings();
        $currentDrawing = $this->currentDrawingQuery()->first();
        $nextContributionCents = LotteryService::jackpotContributionCents(
            $settings['ticket_price_cents'],
            $settings['jackpot_basis_points'],
        );

        return view('admin.lottery.index', [
            'currentDrawing' => $currentDrawing,
            'settings' => $settings,
            'nextTicketPrice' => $settings['ticket_price_cents'] / 100,
            'nextJackpotPercentage' => $settings['jackpot_basis_points'] / 100,
            'nextJackpotContribution' => $nextContributionCents / 100,
            'nextNationSpendLimit' => ($settings['ticket_price_cents'] * $settings['max_tickets_per_nation']) / 100,
            'canManageLottery' => $user->can('manage-lottery'),
        ]);
    }

    public function update(
        UpdateLotterySettingsRequest $request,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $validated = $request->validated();
        $previous = SettingService::getLotterySettings();
        $updated = [
            'sales_enabled' => $request->boolean('lottery_sales_enabled'),
            'ticket_price_cents' => $request->integer('ticket_price') * 100,
            'jackpot_basis_points' => (int) round((float) $validated['jackpot_percentage'] * 100),
            'max_tickets_per_purchase' => $request->integer('max_tickets_per_purchase'),
            'max_tickets_per_nation' => $request->integer('max_tickets_per_nation'),
        ];

        DB::transaction(function () use ($auditLogger, $previous, $updated): void {
            $currentDrawing = $this->currentDrawingQuery()->lockForUpdate()->first();

            SettingService::setLotterySettings($updated);

            if ($currentDrawing) {
                $currentDrawing->update([
                    'sales_enabled' => $updated['sales_enabled'],
                    'max_tickets_per_purchase' => $updated['max_tickets_per_purchase'],
                    'max_tickets_per_nation' => $updated['max_tickets_per_nation'],
                ]);
            }

            $auditLogger->record(
                category: 'settings',
                action: 'lottery_configuration_updated',
                subject: $currentDrawing,
                context: [
                    'changes' => [
                        'sales_enabled' => ['from' => $previous['sales_enabled'], 'to' => $updated['sales_enabled']],
                        'ticket_price_cents' => ['from' => $previous['ticket_price_cents'], 'to' => $updated['ticket_price_cents']],
                        'jackpot_basis_points' => ['from' => $previous['jackpot_basis_points'], 'to' => $updated['jackpot_basis_points']],
                        'max_tickets_per_purchase' => ['from' => $previous['max_tickets_per_purchase'], 'to' => $updated['max_tickets_per_purchase']],
                        'max_tickets_per_nation' => ['from' => $previous['max_tickets_per_nation'], 'to' => $updated['max_tickets_per_nation']],
                    ],
                    'active_drawing_id' => $currentDrawing?->id,
                    'immediate_fields' => ['sales_enabled', 'max_tickets_per_purchase', 'max_tickets_per_nation'],
                    'next_drawing_fields' => ['ticket_price_cents', 'jackpot_basis_points'],
                ],
                message: 'Lottery configuration updated.',
            );
        }, attempts: 3);

        return redirect()->route('admin.lottery.index')->with([
            'alert-message' => 'Lottery configuration updated.',
            'alert-type' => 'success',
        ]);
    }

    /** @return Builder<LotteryDrawing> */
    private function currentDrawingQuery(): Builder
    {
        $now = now()->utc();

        return LotteryDrawing::query()
            ->where('status', LotteryDrawing::STATUS_OPEN)
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>', $now)
            ->latest('starts_at');
    }
}
