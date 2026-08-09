<?php

namespace App\Http\Requests\Discord;

use App\Http\Middleware\ResolveDiscordActor;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;

abstract class DiscordOperationsWorkItemRequest extends FormRequest
{
    final public function authorize(): bool
    {
        $actor = $this->attributes->get(ResolveDiscordActor::ACTOR_ATTRIBUTE);

        return $actor instanceof User && $actor->is_admin;
    }

    final public function actor(): User
    {
        $actor = $this->attributes->get(ResolveDiscordActor::ACTOR_ATTRIBUTE);

        if (! $actor instanceof User || ! $actor->is_admin) {
            throw new AuthorizationException('This command requires an active Nexus administrator with permission to view operations.');
        }

        return $actor;
    }

    /** @return array<string, list<string>> */
    protected function prohibitedAuthorityRules(): array
    {
        return [
            'actor_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'discord_user_id' => ['prohibited'],
            'guild_id' => ['prohibited'],
        ];
    }
}
