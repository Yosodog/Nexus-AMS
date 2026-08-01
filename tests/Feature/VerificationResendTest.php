<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\NationVerification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class VerificationResendTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Notification::fake();
    }

    public function test_resend_rotates_and_sends_one_verification_code(): void
    {
        $user = User::factory()->unverified()->create([
            'verification_code' => 'OLD-CODE',
        ]);
        Event::fake(['eloquent.updated: '.User::class]);

        $this->actingAs($user)
            ->post(route('verification.resend'))
            ->assertRedirect(route('not_verified'))
            ->assertSessionHas('alert-type', 'success');

        $user->refresh();

        $this->assertNotSame('OLD-CODE', $user->verification_code);
        Event::assertDispatchedTimes('eloquent.updated: '.User::class, 1);
        Notification::assertSentTo(
            $user,
            NationVerification::class,
            fn (NationVerification $notification): bool => $notification->verification_code === $user->verification_code,
        );
    }

    public function test_resend_is_rate_limited_per_user(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->post(route('verification.resend'))
            ->assertRedirect(route('not_verified'));

        $this->post(route('verification.resend'))
            ->assertTooManyRequests();

        Notification::assertSentTo($user, NationVerification::class, 1);
    }

    public function test_resend_is_also_rate_limited_per_ip(): void
    {
        $users = User::factory()->unverified()->count(6)->create();

        foreach ($users->take(5) as $user) {
            $this->actingAs($user)
                ->post(route('verification.resend'))
                ->assertRedirect(route('not_verified'));
        }

        $this->actingAs($users->last())
            ->post(route('verification.resend'))
            ->assertTooManyRequests();
    }
}
