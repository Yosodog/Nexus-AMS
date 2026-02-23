<?php

namespace App\Services;

use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

class TrustedDeviceService
{
    public const COOKIE_NAME = 'trusted_device_token';

    public const TRUST_DAYS = 14;

    public function currentTokenHashFromRequest(Request $request): ?string
    {
        $token = (string) $request->cookie(self::COOKIE_NAME, '');

        if ($token === '') {
            return null;
        }

        return hash('sha256', $token);
    }

    public function shouldSkipTwoFactor(Request $request, User $user): bool
    {
        $tokenHash = $this->currentTokenHashFromRequest($request);

        if ($tokenHash === null) {
            return false;
        }

        $trustedDevice = TrustedDevice::query()
            ->where('user_id', $user->id)
            ->where('token_hash', $tokenHash)
            ->where('expires_at', '>', now())
            ->first();

        if (! $trustedDevice) {
            return false;
        }

        $expectedUserAgentHash = $this->userAgentHash($request);

        if (! hash_equals($trustedDevice->user_agent_hash, $expectedUserAgentHash)) {
            return false;
        }

        $trustedDevice->forceFill(['last_used_at' => now()])->save();

        return true;
    }

    public function issueTrustedDeviceCookie(Request $request, User $user): Cookie
    {
        $token = Str::random(80);
        $tokenHash = hash('sha256', $token);
        $expiresAt = now()->addDays(self::TRUST_DAYS);

        TrustedDevice::query()
            ->where('user_id', $user->id)
            ->where('user_agent_hash', $this->userAgentHash($request))
            ->delete();

        TrustedDevice::query()->create([
            'user_id' => $user->id,
            'token_hash' => $tokenHash,
            'user_agent_hash' => $this->userAgentHash($request),
            'user_agent' => Str::limit((string) $request->userAgent(), 255, ''),
            'expires_at' => $expiresAt,
            'last_used_at' => now(),
        ]);

        TrustedDevice::query()
            ->where('expires_at', '<=', now())
            ->delete();

        return cookie()->make(
            name: self::COOKIE_NAME,
            value: $token,
            minutes: now()->diffInMinutes($expiresAt),
            path: '/',
            domain: null,
            secure: (bool) config('session.secure', false),
            httpOnly: true,
            sameSite: config('session.same_site', 'lax')
        );
    }

    private function userAgentHash(Request $request): string
    {
        return hash('sha256', (string) $request->userAgent());
    }
}
