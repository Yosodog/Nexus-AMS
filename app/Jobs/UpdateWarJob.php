<?php

namespace App\Jobs;

use App\Models\War;
use App\Services\AllianceMembershipService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class UpdateWarJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public int $timeout = 20;

    public function __construct(public array $warsData) {}

    public function handle(): void
    {
        try {
            foreach ($this->warsData as $warData) {
                if (! is_array($warData)) {
                    throw new InvalidArgumentException('Each war update must be an array.');
                }

                if (! $this->determineIfAllianceWar($warData)) {
                    continue;
                }

                War::updateFromAPI((object) $warData);
            }
        } catch (Throwable $e) {
            Log::error('Failed to update subscription wars.', [
                'war_ids' => array_values(array_filter(array_map(
                    fn (mixed $warData): ?int => is_array($warData) && isset($warData['id'])
                        ? (int) $warData['id']
                        : null,
                    $this->warsData
                ))),
                'exception_class' => $e::class,
                'error' => $e->getMessage(),
            ]);

            throw $e;
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
