<?php

namespace App\Http\Requests\Discord;

use App\Http\Middleware\ResolveDiscordActor;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;

class DiscordMilcomProjectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->attributes->get(ResolveDiscordActor::ACTOR_ATTRIBUTE);

        return $actor instanceof User
            && ! $actor->disabled
            && $actor->verified_at !== null
            && is_numeric($actor->nation_id)
            && (int) $actor->nation_id > 0;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return $this->prohibitedAuthorityRules();
    }

    public function actor(): User
    {
        $actor = $this->attributes->get(ResolveDiscordActor::ACTOR_ATTRIBUTE);

        if (! $actor instanceof User
            || $actor->disabled
            || $actor->verified_at === null
            || ! is_numeric($actor->nation_id)
            || (int) $actor->nation_id < 1) {
            throw new AuthorizationException('Discord Milcom requires an active verified Nexus nation.');
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
            'operation_id' => ['prohibited'],
            'objective_id' => ['prohibited'],
            'nation_id' => ['prohibited'],
        ];
    }
}
