<?php

namespace App\Jobs;

use App\Models\War;
use App\Services\AllianceMembershipService;
use App\Services\SubscriptionRecordQuarantine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;
use TypeError;
use ValueError;

class UpdateWarJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public int $timeout = 20;

    public function __construct(public array $warsData) {}

    public function handle(): void
    {
        $quarantine = app(SubscriptionRecordQuarantine::class);

        foreach ($this->warsData as $warData) {
            try {
                if (! is_array($warData)) {
                    throw new InvalidArgumentException('Each war update must be an array.');
                }

                if (! $this->determineIfAllianceWar($warData)) {
                    continue;
                }

                War::updateFromAPI((object) $warData);
            } catch (InvalidArgumentException|TypeError|ValueError $exception) {
                $quarantine->quarantine(
                    'war',
                    'update',
                    $warData,
                    'invalid_war_update: '.$exception->getMessage()
                );
            } catch (Throwable $exception) {
                Log::error('Failed to update subscription war.', [
                    'war_id' => is_array($warData) ? ($warData['id'] ?? null) : null,
                    'exception_class' => $exception::class,
                    'error' => $exception->getMessage(),
                ]);

                throw $exception;
            }
        }
    }

    private function determineIfAllianceWar(array $warData): bool
    {
        if (! isset($warData['id']) || ! is_numeric($warData['id']) || (int) $warData['id'] < 1) {
            throw new InvalidArgumentException('A positive war ID is required.');
        }

        /** @var AllianceMembershipService $membershipService */
        $membershipService = app(AllianceMembershipService::class);

        $existingWar = null;

        if (! array_key_exists('att_alliance_id', $warData) || ! array_key_exists('def_alliance_id', $warData)) {
            $existingWar = War::query()
                ->select(['id', 'att_alliance_id', 'def_alliance_id'])
                ->find((int) $warData['id']);
        }

        $attackerAllianceId = array_key_exists('att_alliance_id', $warData)
            ? ($warData['att_alliance_id'] === null ? null : (int) $warData['att_alliance_id'])
            : $existingWar?->att_alliance_id;
        $defenderAllianceId = array_key_exists('def_alliance_id', $warData)
            ? ($warData['def_alliance_id'] === null ? null : (int) $warData['def_alliance_id'])
            : $existingWar?->def_alliance_id;

        return $membershipService->contains($attackerAllianceId)
            || $membershipService->contains($defenderAllianceId);
    }
}
