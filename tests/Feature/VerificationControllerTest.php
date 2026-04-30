<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\FeatureTestCase;

class VerificationControllerTest extends FeatureTestCase
{
    use RefreshDatabase;

    public function test_verify_with_correct_code(): void
    {
        $user = User::factory()->create([
            'verification_code' => 'TESTCODE',
            'verified_at' => null,
        ]);

        $this->actingAs($user)
            ->get(route('verify', ['code' => 'TESTCODE']))
            ->assertRedirect(route('home'))
            ->assertSessionHas('alert-message', 'Your account has been verified! 🥳');

        $this->assertTrue($user->fresh()->isVerified());
        $this->assertNull($user->fresh()->verification_code);
    }

    public function test_verify_with_incorrect_code(): void
    {
        $user = User::factory()->create([
            'verification_code' => 'TESTCODE',
            'verified_at' => null,
        ]);

        $this->actingAs($user)
            ->get(route('verify', ['code' => 'WRONGCODE']))
            ->assertRedirect(route('home'))
            ->assertSessionHas('alert-message', 'Invalid verification code.');

        $this->assertFalse($user->fresh()->isVerified());
        $this->assertEquals('TESTCODE', $user->fresh()->verification_code);
    }

    public function test_verify_fails_if_user_has_no_code_set(): void
    {
        $user = User::factory()->create([
            'verification_code' => null,
            'verified_at' => null,
        ]);

        $this->actingAs($user)
            ->get('/verify/somecode')
            ->assertRedirect(route('home'))
            ->assertSessionHas('alert-message', 'Invalid verification code.');

        $this->assertFalse($user->fresh()->isVerified());
    }
}
