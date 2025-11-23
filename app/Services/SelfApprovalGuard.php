<?php

namespace App\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;

class SelfApprovalGuard
{
    /**
     * Prevent the authenticated user from acting on their own requests.
     *
     * @throws AuthorizationException
     */
    public function ensureNotSelf(?int $requestNationId, ?int $requestUserId = null, string $context = 'act on your own request'): void
    {
        $user = Auth::user();

        if (! $user) {
            return;
        }

        $matchesNation = $requestNationId !== null && $user->nation_id !== null && $user->nation_id === $requestNationId;
        $matchesUser = $requestUserId !== null && $user->id === $requestUserId;

        if ($matchesNation || $matchesUser) {
            throw new AuthorizationException('You cannot '.$context.'.');
        }
    }
}
