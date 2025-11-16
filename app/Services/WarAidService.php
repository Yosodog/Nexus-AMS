<?php

namespace App\Services;

use App\DataTransferObjects\AllianceFinanceData;
use App\Events\AllianceExpenseOccurred;
use App\Models\AllianceFinanceEntry;
use App\Models\Nation;
use App\Models\WarAidRequest;
use App\Notifications\WarAidNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class WarAidService
{
    /**
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

    public function approveAidRequest(WarAidRequest $request, array $adjusted): void
    {
        $resources = $this->extractResources($adjusted);

        $updatedRequest = DB::transaction(function () use ($request, $adjusted, $resources) {
            $request->update([
                ...$adjusted,
                'status' => 'approved',
                'approved_at' => now(),
            ]);

            AccountService::adjustAccountBalance(
                $request->account,
                [
                    ...$resources,
                    'note' => 'Approved war aid request ID #'.$request->id,
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

            return $request->fresh();
        });

        if ($updatedRequest) {
            $this->dispatchWarAidExpenseEvent($updatedRequest, $resources);
        }
    }

    private function extractResources(array $data): array
    {
        return collect(PWHelperService::resources())
            ->mapWithKeys(fn ($res) => [$res => $data[$res] ?? 0])
            ->all();
    }

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

    private function dispatchWarAidExpenseEvent(WarAidRequest $request, array $resources): void
    {
        $financeData = new AllianceFinanceData(
            direction: AllianceFinanceEntry::DIRECTION_EXPENSE,
            category: 'war_aid',
            description: "War aid approved for Nation #{$request->nation_id}",
            date: now(),
            nationId: $request->nation_id,
            accountId: $request->account_id,
            source: $request,
            money: (float) ($resources['money'] ?? 0.0),
            coal: (float) ($resources['coal'] ?? 0.0),
            oil: (float) ($resources['oil'] ?? 0.0),
            uranium: (float) ($resources['uranium'] ?? 0.0),
            iron: (float) ($resources['iron'] ?? 0.0),
            bauxite: (float) ($resources['bauxite'] ?? 0.0),
            lead: (float) ($resources['lead'] ?? 0.0),
            gasoline: (float) ($resources['gasoline'] ?? 0.0),
            munitions: (float) ($resources['munitions'] ?? 0.0),
            steel: (float) ($resources['steel'] ?? 0.0),
            aluminum: (float) ($resources['aluminum'] ?? 0.0),
            food: (float) ($resources['food'] ?? 0.0),
            meta: [
                'war_aid_request_id' => $request->id,
            ]
        );

        event(new AllianceExpenseOccurred($financeData->toArray()));
    }
}
