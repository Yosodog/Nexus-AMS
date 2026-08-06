<?php

namespace App\Http\Controllers;

use App\Exceptions\UserErrorException;
use App\Http\Requests\UpdateMMRAssistantPreferencesRequest;
use App\Models\Account;
use App\Models\MMRConfig;
use App\Services\DirectDepositService;
use App\Services\InactivityModeService;
use App\Services\PWHelperService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class DirectDepositController extends Controller
{
    public DirectDepositService $directDepositService;

    public function __construct()
    {
        $this->directDepositService = app(DirectDepositService::class);
    }

    /**
     * @return RedirectResponse
     */
    public function enroll(Request $request)
    {
        $nation = Auth::user()->nation;

        $request->validate([
            'account_id' => [
                'required',
                $this->activeOwnedAccountRule($nation->id),
            ],
        ]);

        $account = Account::query()->findOrFail($request->integer('account_id'));

        try {
            $this->directDepositService->enroll($nation, $account);
        } catch (UserErrorException $e) {
            return back()->with([
                'alert-message' => $e->getMessage(),
                'alert-type' => 'error',
            ]);
        }

        return back()->with([
            'alert-message' => 'You have been enrolled in Direct Deposit.',
            'alert-type' => 'success',
        ]);
    }

    /**
     * @return RedirectResponse
     */
    public function disenroll()
    {
        $nation = Auth::user()->nation;

        $this->directDepositService->disenroll($nation);
        app(InactivityModeService::class)->recordDirectDepositOptOut($nation);

        return back()->with([
            'alert-message' => 'You have been disenrolled from Direct Deposit.',
            'alert-type' => 'success',
        ]);
    }

    public function updateMMRA(UpdateMMRAssistantPreferencesRequest $request): RedirectResponse
    {
        $nation = Auth::user()->nation;
        $nationId = $nation->id;
        $data = $request->validated();

        $previous = MMRConfig::where('nation_id', $nationId)->first();
        $enabled = array_key_exists('enabled', $data)
            ? (bool) $data['enabled']
            : ($previous?->enabled ?? false);
        $autoCoverResourceDeficits = array_key_exists('auto_cover_resource_deficits', $data)
            ? (bool) $data['auto_cover_resource_deficits']
            : ($previous?->auto_cover_resource_deficits ?? false);

        $resourcePercentages = collect(PWHelperService::resources(false))
            ->mapWithKeys(function (string $resource) use ($data, $previous): array {
                $field = "{$resource}_pct";

                return [
                    $field => array_key_exists($field, $data)
                        ? (float) $data[$field]
                        : (float) ($previous?->getAttribute($field) ?? 0),
                ];
            })
            ->all();

        MMRConfig::updateOrCreate(
            ['nation_id' => $nationId],
            [
                'enabled' => $enabled,
                'auto_cover_resource_deficits' => $autoCoverResourceDeficits,
                'account_id' => $data['account_id'],
                ...$resourcePercentages,
            ]
        );

        return back()->with([
            'alert-message' => 'MMR Assistant preferences saved.',
            'alert-type' => 'success',
        ]);
    }

    private function activeOwnedAccountRule(int $nationId): Exists
    {
        return Rule::exists('accounts', 'id')
            ->where('nation_id', $nationId)
            ->where('frozen', 0)
            ->whereNull('deleted_at');
    }
}
