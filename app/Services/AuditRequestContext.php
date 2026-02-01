<?php

namespace App\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuditRequestContext
{
    public function __construct(private readonly ?Request $request = null) {}

    public function requestId(): ?string
    {
        return $this->request?->attributes->get('request_id');
    }

    public function ip(): ?string
    {
        return $this->request?->ip();
    }

    public function userAgent(): ?string
    {
        return $this->request?->userAgent();
    }

    public function routeName(): ?string
    {
        return $this->request?->route()?->getName();
    }

    public function method(): ?string
    {
        return $this->request?->method();
    }

    /**
     * @return array{type: string, id: int|null, name: string|null}|null
     */
    public function actor(): ?array
    {
        if (! Auth::check()) {
            return null;
        }

        /** @var Authenticatable|null $user */
        $user = Auth::user();

        if (! $user) {
            return null;
        }

        $actorId = method_exists($user, 'getAuthIdentifier') ? $user->getAuthIdentifier() : null;
        $name = property_exists($user, 'name') ? (string) $user->name : null;

        return [
            'type' => 'user',
            'id' => is_numeric($actorId) ? (int) $actorId : null,
            'name' => $name,
        ];
    }
}
