<?php

namespace App\Services\Spy;

use App\Enums\SpyCampaignAllianceRole;
use App\Models\Alliance;
use App\Models\SpyCampaign;
use App\Models\SpyCampaignAlliance;
use App\Models\SpyRound;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Manages spy campaign lifecycle and related records.
 */
class SpyCampaignService
{
    public function create(array $payload): SpyCampaign
    {
        return DB::transaction(function () use ($payload): SpyCampaign {
            $campaign = SpyCampaign::query()->create(Arr::only($payload, ['name', 'description', 'status', 'settings']));

            foreach ($payload['alliances'] ?? [] as $role => $ids) {
                $this->syncAlliances($campaign, (array) $ids, $role);
            }

            foreach ($payload['rounds'] ?? [] as $round) {
                $campaign->rounds()->create($round);
            }

            return $campaign->load('alliances', 'rounds');
        });
    }

    public function update(SpyCampaign $campaign, array $payload): SpyCampaign
    {
        return DB::transaction(function () use ($campaign, $payload): SpyCampaign {
            $campaign->update(Arr::only($payload, ['name', 'description', 'status', 'settings']));

            if (array_key_exists('alliances', $payload)) {
                foreach ($payload['alliances'] as $role => $ids) {
                    $this->syncAlliances($campaign, (array) $ids, $role);
                }
            }

            return $campaign->fresh(['alliances', 'rounds']);
        });
    }

    public function addAlliance(SpyCampaign $campaign, int $allianceId, SpyCampaignAllianceRole $role): SpyCampaignAlliance
    {
        return $campaign->alliances()->create([
            'alliance_id' => $allianceId,
            'role' => $role,
        ]);
    }

    public function removeAlliance(SpyCampaignAlliance $alliance): void
    {
        $alliance->delete();
    }

    public function addRound(SpyCampaign $campaign, array $payload): SpyRound
    {
        $nextNumber = $campaign->rounds()->max('round_number') + 1;

        return $campaign->rounds()->create([
            'round_number' => $payload['round_number'] ?? $nextNumber,
            'op_type' => $payload['op_type'],
            'min_success_chance' => $payload['min_success_chance'] ?? null,
            'status' => $payload['status'] ?? 'draft',
            'notes' => $payload['notes'] ?? null,
        ]);
    }

    /**
     * @param  array<int>  $ids
     */
    protected function syncAlliances(SpyCampaign $campaign, array $ids, string $role): void
    {
        $validRole = SpyCampaignAllianceRole::tryFrom($role)?->value ?? SpyCampaignAllianceRole::ALLY->value;

        $campaign->alliances()->where('role', $validRole)->delete();

        $alliances = Alliance::query()->whereIn('id', $ids)->pluck('id');

        foreach ($alliances as $id) {
            $campaign->alliances()->create([
                'alliance_id' => $id,
                'role' => $validRole,
            ]);
        }
    }
}
