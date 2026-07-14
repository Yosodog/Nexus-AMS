<?php

namespace App\Http\Controllers;

use App\Exceptions\UserErrorException;
use App\Http\Requests\PurchaseLotteryTicketsRequest;
use App\Models\Account;
use App\Models\LotteryDrawing;
use App\Models\LotteryTicket;
use App\Services\AccountService;
use App\Services\AllianceMembershipService;
use App\Services\LotteryRandomizer;
use App\Services\LotteryService;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LotteryController extends Controller
{
    public function index(
        LotteryService $lotteryService,
        AllianceMembershipService $membershipService,
    ): View {
        $user = request()->user();

        if (! $user || ! $membershipService->contains($user->nation?->alliance_id)) {
            abort(403);
        }

        $drawing = $lotteryService->currentDrawing();
        $accounts = AccountService::getAccountsByUser($user);
        $myTickets = LotteryTicket::query()
            ->where('lottery_drawing_id', $drawing->id)
            ->where('user_id', $user->id)
            ->with('account')
            ->latest()
            ->get();
        $recentDrawings = LotteryDrawing::query()
            ->where('status', LotteryDrawing::STATUS_DRAWN)
            ->with(['winningTicket.user.nation', 'winningTicket.account'])
            ->latest('ends_at')
            ->limit(10)
            ->get();

        return view('lottery.index', [
            'drawing' => $drawing,
            'accounts' => $accounts,
            'myTickets' => $myTickets,
            'recentDrawings' => $recentDrawings,
            'remainingTicketCount' => max(
                0,
                LotteryRandomizer::CODE_SPACE_SIZE - $drawing->next_ticket_sequence,
            ),
        ]);
    }

    public function store(
        PurchaseLotteryTicketsRequest $request,
        LotteryService $lotteryService,
    ): RedirectResponse {
        try {
            $account = Account::query()->findOrFail($request->integer('account_id'));
            $tickets = $lotteryService->purchaseTickets(
                $request->user(),
                $account,
                $request->integer('quantity'),
                $request->ip(),
            );

            return redirect()->back()->with([
                'alert-message' => sprintf(
                    'Purchased %d %s: %s',
                    $tickets->count(),
                    str('ticket')->plural($tickets->count()),
                    $tickets->pluck('code')->join(', '),
                ),
                'alert-type' => 'success',
            ]);
        } catch (ValidationException $exception) {
            return redirect()->back()->withErrors($exception->errors())->withInput()->with('alert-type', 'error');
        } catch (UserErrorException $exception) {
            return redirect()->back()->withErrors($exception->getMessage())->withInput()->with('alert-type', 'error');
        } catch (Exception $exception) {
            Log::error('Lottery ticket purchase failed', [
                'message' => $exception->getMessage(),
                'user_id' => $request->user()?->id,
                'account_id' => $request->input('account_id'),
                'quantity' => $request->input('quantity'),
            ]);

            return redirect()->back()->withErrors('There was an error purchasing tickets. No money was moved.');
        }
    }
}
