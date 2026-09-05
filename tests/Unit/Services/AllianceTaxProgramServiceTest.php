<?php

namespace Tests\Unit\Services;

use App\Models\Alliance;
use App\Models\Offshore;
use App\Services\AllianceTaxProgramService;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AllianceTaxProgramServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_resolves_primary_and_offshore_tax_programs_by_alliance(): void
    {
        config()->set('services.pw.alliance_id', 777);
        SettingService::setDirectDepositId(101);
        SettingService::setDirectDepositFallbackId(102);
        SettingService::setGrowthCirclesTaxId(103);
        SettingService::setGrowthCirclesFallbackTaxId(104);

        Alliance::factory()->create(['id' => 888]);
        Offshore::query()->create([
            'name' => 'Tax Offshore',
            'alliance_id' => 888,
            'enabled' => true,
            'direct_deposit_tax_id' => 201,
            'direct_deposit_fallback_tax_id' => 202,
            'growth_circles_tax_id' => 203,
            'growth_circles_fallback_tax_id' => 204,
        ]);
        $taxPrograms = app(AllianceTaxProgramService::class);

        $this->assertSame(101, $taxPrograms->getDirectDepositTaxId(777));
        $this->assertSame(102, $taxPrograms->getDirectDepositFallbackTaxId(777));
        $this->assertSame(103, $taxPrograms->getGrowthCirclesTaxId(777));
        $this->assertSame(104, $taxPrograms->getGrowthCirclesFallbackTaxId(777));
        $this->assertSame(201, $taxPrograms->getDirectDepositTaxId(888));
        $this->assertSame(202, $taxPrograms->getDirectDepositFallbackTaxId(888));
        $this->assertSame(203, $taxPrograms->getGrowthCirclesTaxId(888));
        $this->assertSame(204, $taxPrograms->getGrowthCirclesFallbackTaxId(888));
    }

    public function test_disabled_and_unconfigured_offshores_do_not_enable_tax_programs(): void
    {
        config()->set('services.pw.alliance_id', 777);

        Alliance::factory()->create(['id' => 888]);
        Offshore::query()->create([
            'name' => 'Disabled Offshore',
            'alliance_id' => 888,
            'enabled' => false,
            'direct_deposit_tax_id' => 201,
            'direct_deposit_fallback_tax_id' => 202,
            'growth_circles_tax_id' => 203,
            'growth_circles_fallback_tax_id' => 204,
        ]);

        $taxPrograms = app(AllianceTaxProgramService::class);

        $this->assertFalse($taxPrograms->isDirectDepositEnabled(888));
        $this->assertFalse($taxPrograms->isGrowthCirclesEnabled(888));
        $this->assertFalse($taxPrograms->isDirectDepositEnabled(999));
        $this->assertFalse($taxPrograms->isGrowthCirclesEnabled(999));
        $this->assertFalse($taxPrograms->isDirectDepositEnabled(1000));
        $this->assertFalse($taxPrograms->isGrowthCirclesEnabled(1000));
    }
}
