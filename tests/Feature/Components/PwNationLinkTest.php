<?php

namespace Tests\Feature\Components;

use Tests\TestCase;

class PwNationLinkTest extends TestCase
{
    public function test_it_links_a_nation_to_politics_and_war_in_a_new_tab(): void
    {
        $view = $this->blade(
            '<x-pw-nation-link :nation-id="12345" label="Test Nation" class="font-semibold" />'
        );

        $view
            ->assertSee('Test Nation')
            ->assertSee('https://politicsandwar.com/nation/id=12345', false)
            ->assertSee('target="_blank"', false)
            ->assertSee('rel="noopener noreferrer"', false)
            ->assertSee('font-semibold', false)
            ->assertSee('opens in a new tab');
    }

    public function test_it_renders_plain_text_when_no_valid_nation_id_exists(): void
    {
        $view = $this->blade(
            '<x-pw-nation-link :nation-id="null" label="Unknown nation" class="font-semibold" />'
        );

        $view
            ->assertSee('Unknown nation')
            ->assertSee('font-semibold', false)
            ->assertDontSee('politicsandwar.com', false)
            ->assertDontSee('target="_blank"', false);
    }
}
