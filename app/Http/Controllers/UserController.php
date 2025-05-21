<?php

namespace App\Http\Controllers;

use App\Models\Taxes;
use App\Models\Transaction;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    /**
     * @return Factory|View|Application|object
     */
    public function settings()
    {
        return view('user.settings', ['user' => Auth::user()]);
    }

    /**
     * @param Request $request
     * @return RedirectResponse
     */
    public function updateSettings(Request $request)
    {
        $user = Auth::user();

        $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:users,name,' . $user->id], // Ensure unique name
            'email' => ['required', 'email', 'max:255', 'unique:users,email,' . $user->id], // Ensure unique email
            'password' => ['nullable', Password::defaults(), 'confirmed'],
        ]);

        $user->name = $request->input('name');
        $user->email = $request->input('email');

        if ($request->filled('password')) {
            $user->password = Hash::make($request->input('password'));
        }

        $user->save();

        return redirect()->route('user.settings')->with('alert-message', 'Setting updated successfully!')->with(
            'alert-type',
            'success'
        );
    }

    /**
     * @return View
     */
    public function dashboard(): View
    {
        $user = Auth::user();
        $nation = $user->nation;

        $accountIds = $nation->accounts()->pluck('id');

        $recentTransactions = Transaction::where(function ($query) use ($accountIds) {
            $query->whereIn('from_account_id', $accountIds)
                ->orWhereIn('to_account_id', $accountIds);
        })
            ->latest('created_at')
            ->limit(5)
            ->get();

        $latestSignIn = $nation->signIns()->latest('created_at')->first();

        // Nation age + score per city
        $nationAge = now()->diffInDays($nation->created_at);
        $scorePerCity = $nation->num_cities > 0 ? round($nation->score / $nation->num_cities, 2) : 0;

        // Nation score history (30 days)
        $signIns = $nation->signIns()->latest('created_at')->take(30)->get()->reverse();

        $militaryUnits = ['soldiers', 'tanks', 'aircraft', 'ships', 'missiles', 'nukes'];
        $militaryChart = [
            'labels' => $signIns->pluck('created_at')->map(fn($d) => $d->format('M d'))->toArray(),
            'datasets' => [],
        ];

        foreach ($militaryUnits as $unit) {
            $militaryChart['datasets'][] = [
                'label' => ucfirst($unit),
                'data' => $signIns->pluck($unit)->toArray(),
                'borderColor' => '#' . substr(md5($unit), 0, 6),
                'fill' => false,
            ];
        }

        $scoreHistory = [
            'labels' => $signIns->pluck('created_at')->map(fn($d) => $d->format('M d'))->toArray(),
            'data' => $signIns->pluck('score')->toArray(),
        ];

        // Tax history (last 30 days)
        $taxes = Taxes::where('sender_id', $nation->id)
            ->where('date', '>=', now()->subDays(30))
            ->orderBy('date')
            ->get()
            ->groupBy(fn($t) => $t->date->format('Y-m-d'));

        // Money tax chart
        $moneyTaxChart = [
            'labels' => [],
            'data' => [],
        ];

        foreach ($taxes as $date => $dailyTaxes) {
            $moneyTaxChart['labels'][] = $date;
            $moneyTaxChart['data'][] = round($dailyTaxes->sum('money'), 2);
        }

        // Resource tax chart
        $resources = ['steel', 'aluminum', 'gasoline', 'munitions', 'uranium', 'food'];

        $resourceHoldingsChart = [
            'labels' => $signIns->pluck('created_at')->map(fn($d) => $d->format('M d'))->toArray(),
            'datasets' => [],
        ];

        foreach ($resources as $res) {
            $resourceHoldingsChart['datasets'][] = [
                'label' => ucfirst($res),
                'data' => $signIns->pluck($res)->toArray(),
                'borderColor' => '#' . substr(md5($res), 0, 6),
                'fill' => false,
            ];
        }

        $resourceTaxChart = [
            'labels' => [],
            'resources' => [],
        ];

        // Initialize datasets
        foreach ($resources as $res) {
            $resourceTaxChart['resources'][$res] = [
                'label' => ucfirst($res),
                'data' => [],
            ];
        }

        foreach ($taxes as $date => $dailyTaxes) {
            $resourceTaxChart['labels'][] = $date;

            foreach ($resources as $res) {
                $resourceTaxChart['resources'][$res]['data'][] = round($dailyTaxes->sum($res), 2);
            }
        }

        return view('user.dashboard', [
            'nation' => $nation,
            'latestSignIn' => $signIns->last(),
            'recentTransactions' => $recentTransactions,
            'mmrScore' => 0,
            'taxTotal' => $taxes->sum(fn($group) => $group->sum('money')),
            'grantTotal' => 0,
            'loanTotal' => 0,
            'scoreChart' => $scoreHistory,
            'moneyTaxChart' => $moneyTaxChart,
            'resourceTaxChart' => $resourceTaxChart,
            'nationAge' => $nationAge,
            'scorePerCity' => $scorePerCity,
            'militaryChart' => $militaryChart,
            'resourceHoldingsChart' => $resourceHoldingsChart,
        ]);
    }
}
