<?php

namespace Tests\Unit\Services;

use App\GraphQL\Models\Nation;
use App\Services\RaidTargetActivityFilter;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RaidTargetActivityFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_zero_inactive_turns_preserves_every_target(): void
    {
        SettingService::setRaidActivityCityThreshold(10);
        SettingService::setRaidMinimumInactiveTurns(0);

        $target = $this->target(1, null, null);

        $this->assertSame(
            [1],
            app(RaidTargetActivityFilter::class)->filter(collect([$target]))->pluck('id')->all(),
        );
    }

    public function test_inactivity_applies_only_above_the_city_threshold(): void
    {
        $this->travelTo('2026-08-12 12:00:00');
        SettingService::setRaidActivityCityThreshold(10);
        SettingService::setRaidMinimumInactiveTurns(12);

        $targets = collect([
            $this->target(1, 9, '2026-08-12 12:00:00'),
            $this->target(2, 10, '2026-08-12 12:00:00'),
            $this->target(3, 11, '2026-08-11 13:00:00'),
            $this->target(4, 12, '2026-08-11 12:00:00'),
            $this->target(5, 13, null),
            $this->target(6, null, '2026-08-01 12:00:00'),
        ]);

        $eligibleIds = app(RaidTargetActivityFilter::class)
            ->filter($targets)
            ->pluck('id')
            ->all();

        $this->assertSame([1, 2, 4], $eligibleIds);
    }

    private function target(int $id, ?int $cityCount, ?string $lastActive): Nation
    {
        $nation = new Nation;
        $nation->id = $id;
        $nation->num_cities = $cityCount;
        $nation->last_active = $lastActive;

        return $nation;
    }
}
