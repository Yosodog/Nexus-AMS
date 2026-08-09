<?php

namespace App\Services\Discord;

use App\Models\DiscordAccount;
use App\Models\DiscordActionIntent;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class DiscordWorkflowIntentService
{
    public function create(
        User $actor,
        DiscordAccount $discordAccount,
        string $guildId,
        string $interactionId,
        string $action,
        array $payload,
        DiscordConnectionContext $connection,
    ): DiscordActionIntent {
        return $this->createIntent(
            actor: $actor,
            discordAccount: $discordAccount,
            discordUserId: (string) $discordAccount->discord_id,
            guildId: $guildId,
            interactionId: $interactionId,
            action: $action,
            payload: $payload,
            connection: $connection,
        );
    }

    public function createForDiscordUser(
        string $discordUserId,
        string $guildId,
        string $interactionId,
        string $action,
        array $payload,
        DiscordConnectionContext $connection,
    ): DiscordActionIntent {
        return $this->createIntent(
            actor: null,
            discordAccount: null,
            discordUserId: $discordUserId,
            guildId: $guildId,
            interactionId: $interactionId,
            action: $action,
            payload: $payload,
            connection: $connection,
        );
    }

    public function get(
        User $actor,
        string $guildId,
        string $publicId,
        string $action,
        DiscordConnectionContext $connection,
    ): DiscordActionIntent {
        return $this->getScoped(
            actor: $actor,
            discordUserId: null,
            guildId: $guildId,
            publicId: $publicId,
            action: $action,
            connection: $connection,
        );
    }

    public function getForDiscordUser(
        string $discordUserId,
        string $guildId,
        string $publicId,
        string $action,
        DiscordConnectionContext $connection,
    ): DiscordActionIntent {
        return $this->getScoped(
            actor: null,
            discordUserId: $discordUserId,
            guildId: $guildId,
            publicId: $publicId,
            action: $action,
            connection: $connection,
        );
    }

    public function consume(
        User $actor,
        string $guildId,
        string $publicId,
        string $action,
        callable $operation,
        DiscordConnectionContext $connection,
    ): Model {
        return $this->consumeScoped(
            actor: $actor,
            discordUserId: null,
            guildId: $guildId,
            publicId: $publicId,
            action: $action,
            operation: $operation,
            connection: $connection,
        );
    }

    public function consumeForDiscordUser(
        string $discordUserId,
        string $guildId,
        string $publicId,
        string $action,
        callable $operation,
        DiscordConnectionContext $connection,
    ): Model {
        return $this->consumeScoped(
            actor: null,
            discordUserId: $discordUserId,
            guildId: $guildId,
            publicId: $publicId,
            action: $action,
            operation: $operation,
            connection: $connection,
        );
    }

    private function createIntent(
        ?User $actor,
        ?DiscordAccount $discordAccount,
        string $discordUserId,
        string $guildId,
        string $interactionId,
        string $action,
        array $payload,
        DiscordConnectionContext $connection,
    ): DiscordActionIntent {
        $this->assertContext($discordUserId, $guildId, $connection);
        $token = Str::random(64);
        $intent = DiscordActionIntent::query()->create([
            'token_hash' => hash('sha256', $token),
            'user_id' => $actor?->id,
            'discord_account_id' => $discordAccount?->id,
            'discord_user_id' => $discordUserId,
            'guild_id' => $guildId,
            'connection_id' => $connection->connectionId,
            'connection_generation' => $connection->generation,
            'application_id' => $connection->applicationId,
            'action' => $action,
            'payload' => $payload,
            'status' => DiscordActionIntent::STATUS_DRAFT,
            'created_interaction_id' => $interactionId,
            'expires_at' => now()->addSeconds(max(60, (int) config('services.discord.workflow_action_intent_ttl_seconds', 900))),
        ]);
        $intent->presentedToken = $token;

        return $intent;
    }

    private function getScoped(
        ?User $actor,
        ?string $discordUserId,
        string $guildId,
        string $publicId,
        string $action,
        DiscordConnectionContext $connection,
    ): DiscordActionIntent {
        $this->assertContext($discordUserId, $guildId, $connection, $actor);
        $intent = $this->intentQuery($actor, $discordUserId, $guildId, $publicId, $action, $connection)->first();

        if (! $intent) {
            throw ValidationException::withMessages(['intent_id' => 'Action intent not found.']);
        }

        if ($intent->status === DiscordActionIntent::STATUS_DRAFT && $intent->expires_at->isPast()) {
            $intent->forceFill(['status' => DiscordActionIntent::STATUS_EXPIRED])->save();
        }
        $intent->presentedToken = $publicId;

        return $intent;
    }

    private function consumeScoped(
        ?User $actor,
        ?string $discordUserId,
        string $guildId,
        string $publicId,
        string $action,
        callable $operation,
        DiscordConnectionContext $connection,
    ): Model {
        $this->assertContext($discordUserId, $guildId, $connection, $actor);

        return Cache::lock('discord-action-intent:'.$publicId, 15)->block(5, function () use ($actor, $discordUserId, $guildId, $publicId, $action, $operation, $connection): Model {
            return DB::transaction(function () use ($actor, $discordUserId, $guildId, $publicId, $action, $operation, $connection): Model {
                $intent = $this->intentQuery($actor, $discordUserId, $guildId, $publicId, $action, $connection)
                    ->lockForUpdate()
                    ->first();

                if (! $intent) {
                    throw ValidationException::withMessages(['intent_id' => 'Action intent not found.']);
                }

                if ($intent->status === DiscordActionIntent::STATUS_CONFIRMED && $intent->result_type && $intent->result_id) {
                    /** @var class-string<Model> $resultType */
                    $resultType = $intent->result_type;

                    return $resultType::query()->findOrFail($intent->result_id);
                }

                if ($intent->expires_at->isPast()) {
                    $intent->forceFill(['status' => DiscordActionIntent::STATUS_EXPIRED])->save();
                }

                if ($intent->status !== DiscordActionIntent::STATUS_DRAFT) {
                    throw ValidationException::withMessages(['intent_id' => 'This action intent can no longer be confirmed.']);
                }

                $result = $operation($intent->payload);
                if (! $result instanceof Model) {
                    throw new \LogicException('A Discord workflow confirmation must return an Eloquent model.');
                }

                $intent->forceFill([
                    'status' => DiscordActionIntent::STATUS_CONFIRMED,
                    'confirmed_at' => now(),
                    'result_type' => $result::class,
                    'result_id' => $result->getKey(),
                ])->save();

                return $result;
            }, attempts: 3);
        });
    }

    private function intentQuery(
        ?User $actor,
        ?string $discordUserId,
        string $guildId,
        string $publicId,
        string $action,
        DiscordConnectionContext $connection,
    ): Builder {
        $query = DiscordActionIntent::query()
            ->where('token_hash', hash('sha256', $publicId))
            ->where('guild_id', $guildId)
            ->where('action', $action);

        if ($actor) {
            $query->where('user_id', $actor->id);
        } else {
            $query->where('discord_user_id', $discordUserId);
        }

        return $query->where(function (Builder $binding) use ($connection): void {
            $binding->where(function (Builder $exact) use ($connection): void {
                $exact->where('connection_id', $connection->connectionId)
                    ->where('connection_generation', $connection->generation)
                    ->where('application_id', $connection->applicationId);
            });

            if ($connection->protocolVersion === 1 && $connection->isDedicated()) {
                $binding->orWhere(function (Builder $legacy): void {
                    $legacy->whereNull('connection_id')
                        ->whereNull('connection_generation')
                        ->whereNull('application_id');
                });
            }
        });
    }

    private function assertContext(
        ?string $discordUserId,
        string $guildId,
        DiscordConnectionContext $connection,
        ?User $actor = null,
    ): void {
        if (! hash_equals($connection->guildId, trim($guildId))) {
            throw new InvalidArgumentException('The workflow intent guild does not match its Discord connection.');
        }

        if ($actor === null && preg_match('/^\d{17,20}$/', trim((string) $discordUserId)) !== 1) {
            throw new InvalidArgumentException('A valid Discord user is required for this workflow intent.');
        }
    }
}
