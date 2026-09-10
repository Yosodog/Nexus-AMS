<?php

namespace Tests\Unit\Services;

use App\Models\Alliance;
use App\Models\Nation;
use App\Models\Offshore;
use App\Services\DirectDepositConfigurationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DirectDepositConfigurationResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_enabled_offshore_resolves_its_own_direct_deposit_configuration(): void
    {
        $alliance = Alliance::factory()->create();
        $offshore = $this->createOffshore($alliance);
        $nation = Nation::factory()->create(['alliance_id' => $alliance->id]);

        $configuration = app(DirectDepositConfigurationResolver::class)->forNation($nation);

        $this->assertTrue($configuration->isAvailable());
        $this->assertSame($offshore->id, $configuration->offshoreId);
        $this->assertSame($alliance->id, $configuration->allianceId);
        $this->assertSame(601, $configuration->taxId);
        $this->assertSame(602, $configuration->fallbackTaxId);
    }

    public function test_disabled_incomplete_and_outside_offshores_are_unavailable(): void
    {
        $disabledAlliance = Alliance::factory()->create();
        $incompleteAlliance = Alliance::factory()->create();
        $outsideAlliance = Alliance::factory()->create();

        $this->createOffshore($disabledAlliance, ['enabled' => false]);
        $this->createOffshore($incompleteAlliance, ['direct_deposit_fallback_tax_id' => null]);

        $resolver = app(DirectDepositConfigurationResolver::class);
        $disabled = $resolver->forAlliance($disabledAlliance->id);
        $incomplete = $resolver->forAlliance($incompleteAlliance->id);
        $outside = $resolver->forAlliance($outsideAlliance->id);

        $this->assertFalse($disabled->isAvailable());
        $this->assertStringContainsString('disabled', $disabled->unavailableReason);
        $this->assertFalse($incomplete->isAvailable());
        $this->assertStringContainsString('incomplete', $incomplete->unavailableReason);
        $this->assertFalse($outside->isAvailable());
        $this->assertStringContainsString('not in an alliance', $outside->unavailableReason);
    }

    public function test_same_numeric_tax_ids_can_be_used_by_different_offshores(): void
    {
        $first = $this->createOffshore(Alliance::factory()->create());
        $second = $this->createOffshore(Alliance::factory()->create());
        $resolver = app(DirectDepositConfigurationResolver::class);

        $this->assertTrue($resolver->forAlliance($first->alliance_id)->isAvailable());
        $this->assertTrue($resolver->forAlliance($second->alliance_id)->isAvailable());
        $this->assertSame(
            $resolver->forAlliance($first->alliance_id)->taxId,
            $resolver->forAlliance($second->alliance_id)->taxId,
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createOffshore(Alliance $alliance, array $overrides = []): Offshore
    {
        return Offshore::query()->create(array_merge([
            'name' => 'Resolver Offshore '.$alliance->id,
            'alliance_id' => $alliance->id,
            'enabled' => true,
            'direct_deposit_enabled' => true,
            'direct_deposit_tax_id' => 601,
            'direct_deposit_fallback_tax_id' => 602,
            'priority' => 1,
            'api_key' => str_repeat('b', 20),
            'mutation_key' => 'mutation-secret',
        ], $overrides));
    }
}
