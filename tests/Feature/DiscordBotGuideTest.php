<?php

namespace Tests\Feature;

use App\Livewire\Admin\AppNavbar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;

class DiscordBotGuideTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;

    public function test_guest_cannot_open_discord_bot_guide(): void
    {
        $this->get(route('user.discord-bot-guide'))
            ->assertRedirect(route('login'));
    }

    public function test_linked_verified_member_can_read_complete_discord_bot_guide(): void
    {
        $user = $this->createVerifiedUser();
        $this->attachDiscordAccount($user);

        $response = $this->actingAs($user)
            ->get(route('user.discord-bot-guide'))
            ->assertOk()
            ->assertSeeText('Discord bot guide')
            ->assertSeeText('Private notifications')
            ->assertSeeText('Automatic Discord features')
            ->assertSeeText('Intel report capture')
            ->assertSeeText('Staff commands');

        foreach ([
            '/verify', '/ping', '/accounts', '/transactions', '/deposit', '/withdraw', '/requests',
            '/grant browse', '/grant apply', '/grant city', '/grant status',
            '/loan apply', '/loan status', '/loan pay', '/waraid apply', '/waraid status',
            '/rebuild apply', '/rebuild status', '/raid', '/war active', '/war assignments',
            '/war counter', '/war simulate', '/spy assignments', '/apply', '/applications status',
            '/applications queue', '/applications review', '/applications approve', '/applications deny',
            '/approve', '/deny', '/archivecounter', '/sweepbank',
        ] as $command) {
            $response->assertSeeText($command);
        }
    }

    public function test_staff_account_menu_links_to_discord_bot_guide(): void
    {
        $admin = $this->createVerifiedAdmin();
        $this->attachDiscordAccount($admin);

        $this->actingAs($admin);

        Livewire::test(AppNavbar::class)
            ->assertSee('Discord bot guide')
            ->assertSee(route('user.discord-bot-guide'));
    }
}
