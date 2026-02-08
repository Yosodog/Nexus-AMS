<?php

namespace App\Http\Controllers;

use App\Exceptions\UserErrorException;
use App\Http\Requests\SellMarketRequest;
use App\Models\Account;
use App\Models\MarketTransaction;
use App\Services\AccountService;
use App\Services\AllianceMembershipService;
use App\Services\MarketService;
use App\Services\PWHelperService;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class MarketController extends Controller
{
    public function index(MarketService $marketService, AllianceMembershipService $membershipService): View
    {
        $user = request()->user();

        if (! $user || ! $membershipService->contains($user->nation?->alliance_id)) {
            abort(403);
        }

        $marketResources = $marketService->getEnabledMarketResourcesForUser();
        $resourceOptions = collect($marketResources)->pluck('resource')->values()->all();
        $priceMap = collect($marketResources)->keyBy('resource')->all();

        $accounts = AccountService::getAccountsByUser($user);
        $resourceKeys = PWHelperService::resources();
        $accountBalances = $accounts->mapWithKeys(function (Account $account) use ($resourceKeys): array {
            $balances = [];

            foreach ($resourceKeys as $resource) {
                $balances[$resource] = (float) $account->{$resource};
            }

            return [$account->id => $balances];
        })->all();
        $resourceLabels = collect($resourceKeys)
            ->mapWithKeys(fn (string $resource): array => [$resource => str_replace('_', ' ', $resource)])
            ->all();
        $recentTransactions = MarketTransaction::query()
            ->where('user_id', $user->id)
            ->latest()
            ->limit(20)
            ->get();

        return view('market.index', [
            'marketResources' => $marketResources,
            'resourceOptions' => $resourceOptions,
            'priceMap' => $priceMap,
            'accounts' => $accounts,
            'accountBalances' => $accountBalances,
            'resourceLabels' => $resourceLabels,
            'recentTransactions' => $recentTransactions,
        ]);
    }

    public function sell(SellMarketRequest $request, MarketService $marketService): RedirectResponse
    {
        try {
            $account = Account::query()->findOrFail($request->integer('account_id'));
            $marketService->sell(
                $request->user(),
                $account,
                $request->string('resource')->toString(),
                (float) $request->input('amount')
            );

            return redirect()->back()->with([
                'alert-message' => 'Sale completed successfully.',
                'alert-type' => 'success',
            ]);
        } catch (ValidationException $exception) {
            return redirect()->back()->withErrors($exception->errors())->with('alert-type', 'error');
        } catch (UserErrorException $exception) {
            return redirect()->back()->withErrors($exception->getMessage())->with('alert-type', 'error');
        } catch (Exception $exception) {
            Log::error('Alliance market sell failed', [
                'message' => $exception->getMessage(),
                'user_id' => $request->user()?->id,
                'account_id' => $request->input('account_id'),
                'resource' => $request->input('resource'),
            ]);

            return redirect()->back()->withErrors('There was an error completing your sale. Please try again.');
        }
    }
}
