<?php

namespace App\Actions\Fortify;

use App\Services\TrustedDeviceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cookie;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;

class TwoFactorLoginResponse implements TwoFactorLoginResponseContract
{
    public function __construct(private readonly TrustedDeviceService $trustedDeviceService) {}

    public function toResponse($request): mixed
    {
        $user = $request->user();

        if ($request->boolean('trust_device') && $user) {
            Cookie::queue($this->trustedDeviceService->issueTrustedDeviceCookie($request, $user));
        }

        return $request->wantsJson()
            ? new JsonResponse('', 204)
            : redirect()->intended(route('user.dashboard'));
    }
}
