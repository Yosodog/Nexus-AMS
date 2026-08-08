<?php

namespace App\Services\Discord;

use App\Enums\DiscordActorContextState;
use App\Models\DiscordAccount;
use App\Models\User;

final readonly class DiscordActorContext
{
    /**
     * @param  array{label: string, deep_link_path: string}|null  $userAction
     */
    public function __construct(
        public DiscordActorContextState $state,
        public string $message,
        public ?User $actor = null,
        public ?DiscordAccount $discordAccount = null,
        public ?array $userAction = null,
    ) {}

    public function isReady(): bool
    {
        return $this->state === DiscordActorContextState::Ready;
    }

    /**
     * Return only fields that are safe to expose before a linked actor is resolved.
     *
     * @return array{
     *     contract_version: int,
     *     state: string,
     *     message: string,
     *     user_action: array{label: string, deep_link_path: string}|null
     * }
     */
    public function safePayload(): array
    {
        return [
            'contract_version' => 1,
            'state' => $this->state->value,
            'message' => $this->message,
            'user_action' => $this->userAction,
        ];
    }
}
