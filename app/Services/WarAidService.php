<?php

namespace App\Services;

use App\Models\Nation;
use App\Models\WarAidRequest;
use App\Notifications\WarAidNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class WarAidService
{
    /**
     * @param Nation $nation
     * @param array $data
     * @return WarAidRequest
     * @throws ValidationException
     */
    public function submitAidRequest(Nation $nation, array $data): WarAidRequest
    {
        if (WarAidRequest::where('nation_id', $nation->id)->where('status', 'pending')->exists()) {
            throw ValidationException::withMessages([
                'pending' => 'You already have a pending war aid request.',
            ]);
        }

        // Validate ownership of the account
        $account = $nation->accounts()->findOrFail($data['account_id']);

        // Validate alliance membership
        (new NationEligibilityValidator($nation))->validateAllianceMembership(); // PHP 8.4 anyone???

        return WarAidRequest::create([
            ...$data,
            'nation_id' => $nation->id,
        ]);
    }

    /**
     * @param WarAidRequest $request
     * @param array $adjusted
     * @return void
     */
    public function approveAidRequest(WarAidRequest $request, array $adjusted): void
    {
        DB::transaction(function () use ($request, $adjusted) {
            $request->update([
                ...$adjusted,
                'status' => 'approved',
                'approved_at' => now(),
            ]);

            AccountService::adjustAccountBalance(
                $request->account,
                [
                    ...$this->extractResources($adjusted),
                    'note' => 'Approved war aid request ID #' . $request->id,
                ],
                adminId: auth()->id(),
                ipAddress: request()->ip()
            );

            $request->nation->notify(
                new WarAidNotification(
                    nation_id: $request->nation_id,
                    request: $request,
                    status: 'approved'
                )
            );
        });
    }

    /**
     * @param array $data
     * @return array
     */
    private function extractResources(array $data): array
    {
        return collect(PWHelperService::resources())
            ->mapWithKeys(fn($res) => [$res => $data[$res] ?? 0])
            ->all();
    }

    /**
     * @param WarAidRequest $request
     * @return void
     */
    public function denyAidRequest(WarAidRequest $request): void
    {
        $request->update([
            'status' => 'denied',
            'denied_at' => now(),
        ]);

        $request->nation->notify(
            new WarAidNotification(
                nation_id: $request->nation_id,
                request: $request,
                status: 'denied'
            )
        );
    }

    /**
     * @param Nation $nation
     * @return array
     */
    public function getNationAvailableResources(Nation $nation): array
    {
        try {
            $live = [];

            foreach ($nation->accounts as $account) {
                foreach (PWHelperService::resources(false, false, true) as $resource) {
                    $live[$resource] = ($live[$resource] ?? 0) + ($account->$resource ?? 0);
                }
            }

            foreach (PWHelperService::resources(false, false, true) as $resource) {
                $live[$resource] = ($live[$resource] ?? 0) + ($nation->resources->$resource ?? 0);
            }

            return $live;
        } catch (Throwable $e) {
            return optional($nation->signIns()->latest()->first())->resources ?? [];
        }
    }
}