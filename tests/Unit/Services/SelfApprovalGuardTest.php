<?php

namespace Tests\Unit\Services;

use App\Models\User;
use App\Services\SelfApprovalGuard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Tests\FeatureTestCase;

class SelfApprovalGuardTest extends FeatureTestCase
{
    public function test_guard_rejects_matching_nation_ids(): void
    {
        $user = \Mockery::mock(User::factory()->make(['nation_id' => 123]))->makePartial();
        $user->shouldReceive('can')->with('bypass-self-restrictions')->andReturn(false);
        $this->be($user);

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('You cannot approve your own request.');

        app(SelfApprovalGuard::class)->ensureNotSelf(123, null, 'approve your own request');
    }

    public function test_guard_rejects_matching_user_ids(): void
    {
        $user = \Mockery::mock(User::factory()->make(['id' => 99, 'nation_id' => 123]))->makePartial();
        $user->shouldReceive('can')->with('bypass-self-restrictions')->andReturn(false);
        $this->be($user);

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('You cannot act on your own request.');

        app(SelfApprovalGuard::class)->ensureNotSelf(null, 99);
    }

    public function test_guard_allows_users_with_bypass_permission(): void
    {
        $user = \Mockery::mock(User::factory()->make(['nation_id' => 123]))->makePartial();
        $user->shouldReceive('can')->with('bypass-self-restrictions')->andReturn(true);
        $this->be($user);

        app(SelfApprovalGuard::class)->ensureNotSelf(123, null, 'approve your own request');

        $this->assertTrue(true);
    }

    public function test_guard_allows_guests(): void
    {
        Auth::shouldReceive('user')->andReturn(null);

        app(SelfApprovalGuard::class)->ensureNotSelf(123, 99);

        $this->assertTrue(true);
    }
}
