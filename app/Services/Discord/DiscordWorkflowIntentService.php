<?php

namespace App\Services\Discord;

use App\Models\DiscordAccount;
use App\Models\DiscordActionIntent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DiscordWorkflowIntentService
{
    public function create(
        User $actor,
        DiscordAccount $discordAccount,
        string $guildId,
        string $interactionId,
        string $action,
        array $payload,
    ): DiscordActionIntent {
        $token = Str::random(64);
        $intent = DiscordActionIntent::query()->create([
            'token_hash' => hash('sha256', $token),
            'user_id' => $actor->id,
            'discord_account_id' => $discordAccount->id,
            'guild_id' => $guildId,
            'action' => $action,
            'payload' => $payload,
            'status' => DiscordActionIntent::STATUS_DRAFT,
            'created_interaction_id' => $interactionId,
            'expires_at' => now()->addSeconds(max(60, (int) config('services.discord.workflow_action_intent_ttl_seconds', 900))),
        ]);
        $intent->presentedToken = $token;

        return $intent;
    }

    public function get(User $actor, string $guildId, string $publicId, string $action): DiscordActionIntent
    {
        $intent = DiscordActionIntent::query()
            ->where('token_hash', hash('sha256', $publicId))
            ->where('user_id', $actor->id)
            ->where('guild_id', $guildId)
            ->where('action', $action)
            ->first();

        if (! $intent) {
            throw ValidationException::withMessages(['intent_id' => 'Action intent not found.']);
        }

        if ($intent->status === DiscordActionIntent::STATUS_DRAFT && $intent->expires_at->isPast()) {
            $intent->forceFill(['status' => DiscordActionIntent::STATUS_EXPIRED])->save();
        }
        $intent->presentedToken = $publicId;

        return $intent;
    }

    public function consume(
        User $actor,
        string $guildId,
        string $publicId,
        string $action,
        callable $operation,
    ): Model {
        return Cache::lock('discord-action-intent:'.$publicId, 15)->block(5, function () use ($actor, $guildId, $publicId, $action, $operation): Model {
            return DB::transaction(function () use ($actor, $guildId, $publicId, $action, $operation): Model {
                $intent = DiscordActionIntent::query()
                    ->where('token_hash', hash('sha256', $publicId))
                    ->where('user_id', $actor->id)
                    ->where('guild_id', $guildId)
                    ->where('action', $action)
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
}
