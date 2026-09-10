<?php

namespace Tests\Unit\Services;

use App\Models\Nation;
use App\Models\NationResources;
use App\Services\Alerts\ResourceShortfallService;
use App\Services\Economy\EconomyRules;
use App\Services\NationProfitabilityService;
use App\Services\PWHelperService;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ResourceShortfallServiceTest extends TestCase
{
    #[Test]
    public function projects_next_turn_alerts_and_a_twelve_turn_withdrawal(): void
    {
        $nation = $this->nationWithResources([
            'coal' => 5,
            'steel' => 1,
            'iron' => 0,
        ]);
        $profitability = $this->createMock(NationProfitabilityService::class);
        $profitability->expects($this->once())
            ->method('getDailyTradeResourceShortfallProjection')
            ->with($nation, ResourceShortfallService::MAXIMUM_DATA_AGE_HOURS)
            ->willReturn([
                'calculated_at' => now()->subHour(),
                'shortfalls_per_day' => [
                    ...array_fill_keys(EconomyRules::TRADE_RESOURCES, 0.0),
                    'coal' => 120.0,
                    'steel' => 12.0,
                    'iron' => 1.21,
                ],
            ]);

        $projection = (new ResourceShortfallService($profitability))->project($nation);

        $this->assertNotNull($projection);
        $this->assertSame('115.00', $projection['resources']['coal']);
        $this->assertSame('1.21', $projection['resources']['iron']);
        $this->assertSame('0.00', $projection['resources']['steel']);
        $this->assertSame(['coal', 'iron'], collect($projection['lines'])->pluck('resource')->all());
        $this->assertSame('10.00', $projection['lines'][0]['next_turn_requirement']);
        $this->assertSame('0.11', $projection['lines'][1]['next_turn_requirement']);
        $this->assertSame(64, strlen($projection['snapshot_fingerprint']));
    }

    #[Test]
    public function stale_resource_data_fails_closed_without_reading_profitability(): void
    {
        $nation = $this->nationWithResources([], now()->subHours(4));
        $profitability = $this->createMock(NationProfitabilityService::class);
        $profitability->expects($this->never())->method('getDailyTradeResourceShortfallProjection');

        $this->assertNull((new ResourceShortfallService($profitability))->project($nation));
    }

    /** @param array<string, float|int> $overrides */
    private function nationWithResources(array $overrides, ?Carbon $updatedAt = null): Nation
    {
        $nation = new Nation;
        $nation->id = 123;
        $resources = new NationResources;
        foreach (PWHelperService::resources(includeCredits: true) as $resource) {
            $resources->{$resource} = $overrides[$resource] ?? 0;
        }
        $resources->updated_at = $updatedAt ?? now();
        $nation->setRelation('resources', $resources);

        return $nation;
    }
}
