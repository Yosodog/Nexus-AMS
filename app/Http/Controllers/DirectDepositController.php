<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\MMRConfig;
use App\Services\DirectDepositService;
use App\Services\PWHelperService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class DirectDepositController extends Controller
{
    public DirectDepositService $directDepositService;

    public function __construct()
    {
        $this->directDepositService = app(DirectDepositService::class);
    }

    /**
     * @return \Illuminate\Http\RedirectResponse
     */
    public function enroll(Request $request)
    {
        $request->validate(['account_id' => 'required|exists:accounts,id']);

        $nation = Auth::user()->nation;
        $account = Account::findOrFail($request->account_id);

        $this->directDepositService->enroll($nation, $account);

        return back()->with([
            'alert-message' => 'You have been enrolled in Direct Deposit.',
            'alert-type' => 'success',
        ]);
    }

    /**
     * @return \Illuminate\Http\RedirectResponse
     */
    public function disenroll()
    {
        $nation = Auth::user()->nation;

        $this->directDepositService->disenroll($nation);

        return back()->with([
            'alert-message' => 'You have been disenrolled from Direct Deposit.',
            'alert-type' => 'success',
        ]);
    }

    /**
     * @return \Illuminate\Http\RedirectResponse
     */
    public function updateMMRA(Request $request)
    {
        $nation = Auth::user()->nation;
        $nationId = $nation->id;

        $data = $request->validate(array_merge([
            'enabled' => 'nullable|boolean',
            'account_id' => ['required', Rule::exists('accounts', 'id')->where('nation_id', $nationId)],
        ],
            collect(PWHelperService::resources(false))->mapWithKeys(fn ($r) => [
                "{$r}_pct" => 'nullable|numeric|min:0|max:100',
            ])->toArray()));

        $total = collect($data)->filter(fn ($v, $k) => str_ends_with($k, '_pct'))->sum();

        if ($total > 100) {
            return back()->with([
                'alert-message' => 'Total percentage cannot exceed 100%',
                'alert-type' => 'error',
            ]);
        } else {
            if ($total < 0) {
                return back()->with([
                    'alert-message' => 'Total percentage cannot be below 0%',
                    'alert-type' => 'error',
                ]);
            }
        }

        $previous = MMRConfig::where('nation_id', $nationId)->first();
        $enabled = array_key_exists('enabled', $data)
            ? (bool) $data['enabled']
            : ($previous?->enabled ?? false);

        MMRConfig::updateOrCreate(
            ['nation_id' => $nationId],
            array_merge(
                ['enabled' => $enabled],
                ['account_id' => $data['account_id']],
                collect(PWHelperService::resources(false))->mapWithKeys(fn ($r) => [
                    "{$r}_pct" => floatval($data["{$r}_pct"] ?? 0),
                ])->toArray()
            )
        );

        return back()->with([
            'alert-message' => 'MMR Assistant preferences saved.',
            'alert-type' => 'success',
        ]);
    }
}
