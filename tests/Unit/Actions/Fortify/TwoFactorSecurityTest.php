<?php

namespace Tests\Unit\Actions\Fortify;

use App\Actions\Fortify\RedirectIfTwoFactorAuthenticatable;
use App\Actions\Fortify\TwoFactorLoginResponse;
use App\Models\User;
use App\Services\TrustedDeviceService;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Laravel\Fortify\LoginRateLimiter;
use Mockery;
use Symfony\Component\HttpFoundation\Cookie as HttpCookie;
use Tests\FeatureTestCase;

class TwoFactorSecurityTest extends FeatureTestCase
{
    public function test_two_factor_login_response_queues_trusted_device_cookie_only_when_requested(): void
    {
        Cookie::spy();

        $user = User::factory()->make();
        $queuedCookie = new HttpCookie('trusted_device_token', 'secret');
        $trustedDevices = Mockery::mock(TrustedDeviceService::class);
        $trustedDevices->shouldReceive('issueTrustedDeviceCookie')->once()->andReturn($queuedCookie);

        $request = Request::create('/two-factor-challenge', 'POST', ['trust_device' => '1']);
        $request->headers->set('Accept', 'application/json');
        $request->setUserResolver(fn () => $user);

        $response = (new TwoFactorLoginResponse($trustedDevices))->toResponse($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(204, $response->getStatusCode());
        Cookie::shouldHaveReceived('queue')->once()->with($queuedCookie);
    }

    public function test_two_factor_login_response_skips_cookie_queueing_when_device_trust_is_not_requested(): void
    {
        Cookie::spy();

        $trustedDevices = Mockery::mock(TrustedDeviceService::class);
        $trustedDevices->shouldReceive('issueTrustedDeviceCookie')->never();

        $request = Request::create('/two-factor-challenge', 'POST');
        $request->setUserResolver(fn () => User::factory()->make());

        $response = (new TwoFactorLoginResponse($trustedDevices))->toResponse($request);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        Cookie::shouldNotHaveReceived('queue');
    }

    public function test_redirect_if_two_factor_authenticatable_skips_challenge_for_trusted_devices(): void
    {
        $user = User::factory()->make([
            'two_factor_secret' => encrypt('secret'),
            'two_factor_confirmed_at' => now(),
        ]);
        $trustedDevices = Mockery::mock(TrustedDeviceService::class);
        $trustedDevices->shouldReceive('shouldSkipTwoFactor')->once()->with(Mockery::type(Request::class), $user)->andReturn(true);

        $action = Mockery::mock(
            RedirectIfTwoFactorAuthenticatable::class,
            [$this->createMock(StatefulGuard::class), $this->createMock(LoginRateLimiter::class), $trustedDevices]
        )->makePartial()->shouldAllowMockingProtectedMethods();

        $action->shouldReceive('validateCredentials')->once()->andReturn($user);
        $action->shouldReceive('twoFactorChallengeResponse')->never();

        $nextWasCalled = false;
        $response = $action->handle(Request::create('/login', 'POST'), function () use (&$nextWasCalled) {
            $nextWasCalled = true;

            return response('ok');
        });

        $this->assertTrue($nextWasCalled);
        $this->assertSame('ok', $response->getContent());
    }

    public function test_redirect_if_two_factor_authenticatable_returns_challenge_when_device_is_not_trusted(): void
    {
        $user = User::factory()->make([
            'two_factor_secret' => encrypt('secret'),
            'two_factor_confirmed_at' => now(),
        ]);
        $trustedDevices = Mockery::mock(TrustedDeviceService::class);
        $trustedDevices->shouldReceive('shouldSkipTwoFactor')->once()->andReturn(false);

        $action = Mockery::mock(
            RedirectIfTwoFactorAuthenticatable::class,
            [$this->createMock(StatefulGuard::class), $this->createMock(LoginRateLimiter::class), $trustedDevices]
        )->makePartial()->shouldAllowMockingProtectedMethods();

        $action->shouldReceive('validateCredentials')->once()->andReturn($user);
        $action->shouldReceive('twoFactorChallengeResponse')->once()->andReturn(response('challenge'));

        $nextWasCalled = false;
        $response = $action->handle(Request::create('/login', 'POST'), function () use (&$nextWasCalled) {
            $nextWasCalled = true;

            return response('ok');
        });

        $this->assertFalse($nextWasCalled);
        $this->assertSame('challenge', $response->getContent());
    }
}
