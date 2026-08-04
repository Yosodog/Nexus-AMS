<?php

namespace Tests\Feature\Components;

use Tests\TestCase;

class MilcomMilitarySummaryTest extends TestCase
{
    public function test_it_formats_all_conventional_military_values(): void
    {
        $view = $this->blade(<<<'BLADE'
            <x-milcom.military-summary
                :nation="['soldiers' => 150000, 'tanks' => 10000, 'aircraft' => 1200, 'ships' => 120]"
                variant="tiles"
                dynamic
            />
        BLADE);

        $view
            ->assertSee('Soldiers')
            ->assertSee('150,000')
            ->assertSee('Tanks')
            ->assertSee('10,000')
            ->assertSee('Aircraft')
            ->assertSee('1,200')
            ->assertSee('Ships')
            ->assertSee('120')
            ->assertSee('data-milcom-field="soldiers"', false)
            ->assertSee('data-milcom-military="soldiers"', false)
            ->assertSee('aria-label="Military"', false);
    }

    public function test_it_marks_unavailable_military_data_as_unknown(): void
    {
        $this->blade('<x-milcom.military-summary :nation="[]" />')
            ->assertSee('Not available');
    }
}
