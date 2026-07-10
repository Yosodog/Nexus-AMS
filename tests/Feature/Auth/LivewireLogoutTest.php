<?php

namespace Tests\Feature\Auth;

use App\Livewire\Admin\AppNavbar;
use App\Livewire\AppHeader;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LivewireLogoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_header_logs_out_and_redirects_home(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(AppHeader::class)
            ->call('logout')
            ->assertRedirect(route('home'));

        $this->assertGuest();
    }

    public function test_admin_navbar_logs_out_and_redirects_home(): void
    {
        $user = User::factory()->create(['is_admin' => true]);

        Livewire::actingAs($user)
            ->test(AppNavbar::class)
            ->call('logout')
            ->assertRedirect(route('home'));

        $this->assertGuest();
    }
}
