<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;

class MemberSettingsPresentationTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;

    public function test_settings_and_primary_navigation_only_expose_available_destinations(): void
    {
        $user = $this->createVerifiedUser();
        $this->attachDiscordAccount($user);

        $response = $this->actingAs($user)->get(route('user.settings'));

        $response
            ->assertOk()
            ->assertSee('Use these shortcuts to return to your dashboard or manage Discord verification.')
            ->assertSee(route('user.dashboard'), false)
            ->assertSee(route('discord.verify.show'), false);

        $html = $response->getContent();
        $this->assertStringNotContainsString('coming soon', strtolower($html));

        $document = new \DOMDocument;
        $previousSetting = libxml_use_internal_errors(true);
        $document->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previousSetting);

        $xpath = new \DOMXPath($document);

        $this->assertSame(0, $xpath->query('//nav//a[not(@href)]')->length);
        $this->assertSame(0, $xpath->query('//nav//*[@disabled or @aria-disabled="true"]')->length);
    }
}
