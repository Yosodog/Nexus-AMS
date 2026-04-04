<?php

namespace Tests\Unit\Services;

use App\Models\TrustedDevice;
use App\Models\User;
use App\Services\TrustedDeviceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\FeatureTestCase;

class TrustedDeviceServiceTest extends FeatureTestCase
{
    use RefreshDatabase;

    public function test_should_skip_two_factor_accepts_matching_trusted_device_and_updates_last_used_at(): void
    {
        $user = User::factory()->create();
        $token = str_repeat('a', 80);
        $previousLastUsedAt = now()->subDay();

        $device = TrustedDevice::query()->create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'user_agent_hash' => hash('sha256', 'Codex Test'),
            'user_agent' => 'Codex Test',
            'expires_at' => now()->addDays(7),
            'last_used_at' => $previousLastUsedAt,
        ]);

        $request = Request::create('/login', 'POST', [], [
            TrustedDeviceService::COOKIE_NAME => $token,
        ], [], [
            'HTTP_USER_AGENT' => 'Codex Test',
        ]);

        $this->assertTrue(app(TrustedDeviceService::class)->shouldSkipTwoFactor($request, $user));
        $this->assertTrue($device->fresh()->last_used_at->gt($previousLastUsedAt));
    }

    public function test_should_skip_two_factor_rejects_mismatched_user_agent_hashes(): void
    {
        $user = User::factory()->create();
        $token = str_repeat('b', 80);

        TrustedDevice::query()->create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'user_agent_hash' => hash('sha256', 'Expected Agent'),
            'user_agent' => 'Expected Agent',
            'expires_at' => now()->addDays(7),
        ]);

        $request = Request::create('/login', 'POST', [], [
            TrustedDeviceService::COOKIE_NAME => $token,
        ], [], [
            'HTTP_USER_AGENT' => 'Different Agent',
        ]);

        $this->assertFalse(app(TrustedDeviceService::class)->shouldSkipTwoFactor($request, $user));
    }

    public function test_issue_trusted_device_cookie_rotates_same_user_agent_records_and_cleans_expired_rows(): void
    {
        config()->set('session.domain', '.nexus-ams.test');
        config()->set('session.same_site', 'strict');
        config()->set('session.secure', false);

        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $request = Request::create('https://nexus-ams.test/login', 'POST', [], [], [], [
            'HTTPS' => 'on',
            'HTTP_USER_AGENT' => str_repeat('Trusted Browser ', 30),
        ]);

        TrustedDevice::query()->create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', 'old-token'),
            'user_agent_hash' => hash('sha256', (string) $request->userAgent()),
            'user_agent' => 'old',
            'expires_at' => now()->addDays(7),
        ]);

        $expired = TrustedDevice::query()->create([
            'user_id' => $otherUser->id,
            'token_hash' => hash('sha256', 'expired-token'),
            'user_agent_hash' => hash('sha256', 'Expired Browser'),
            'user_agent' => 'Expired Browser',
            'expires_at' => now()->subMinute(),
        ]);

        $cookie = app(TrustedDeviceService::class)->issueTrustedDeviceCookie($request, $user);

        $this->assertSame(TrustedDeviceService::COOKIE_NAME, $cookie->getName());
        $this->assertSame('.nexus-ams.test', $cookie->getDomain());
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('strict', $cookie->getSameSite());

        $devices = TrustedDevice::query()->where('user_id', $user->id)->get();

        $this->assertCount(1, $devices);
        $this->assertSame(hash('sha256', $cookie->getValue()), $devices->first()->token_hash);
        $this->assertLessThanOrEqual(255, strlen((string) $devices->first()->user_agent));
        $this->assertDatabaseMissing('trusted_devices', ['id' => $expired->id]);
    }
}
