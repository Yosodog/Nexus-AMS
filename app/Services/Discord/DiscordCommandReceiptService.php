<?php

namespace App\Services\Discord;

use App\Http\Middleware\VerifyDiscordInteraction;
use App\Models\DiscordCommandReceipt;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DiscordCommandReceiptService
{
    /**
     * @return array{receipt: DiscordCommandReceipt|null, response: JsonResponse|null}
     */
    public function claim(Request $request, ?User $actor): array
    {
        $interactionId = trim((string) ($request->attributes->get(VerifyDiscordInteraction::INTERACTION_ATTRIBUTE)
            ?? $request->header('X-Discord-Interaction-ID')));

        if (preg_match('/^\d{1,32}$/', $interactionId) !== 1) {
            return ['receipt' => null, 'response' => $this->error(
                'invalid_discord_interaction',
                'A valid Discord interaction ID is required for mutations.',
                400,
            )];
        }

        $attributes = [
            'interaction_id' => $interactionId,
            'connection_id' => $request->attributes->get(VerifyDiscordInteraction::CONNECTION_ATTRIBUTE)?->connectionId,
            'connection_generation' => $request->attributes->get(VerifyDiscordInteraction::GENERATION_ATTRIBUTE),
            'relay_idempotency_key' => $request->attributes->get(VerifyDiscordInteraction::IDEMPOTENCY_ATTRIBUTE),
            'guild_id' => trim((string) ($request->attributes->get(VerifyDiscordInteraction::GUILD_ATTRIBUTE)
                ?? $request->header('X-Discord-Guild-ID'))),
            'discord_user_id' => trim((string) ($request->attributes->get(VerifyDiscordInteraction::USER_ATTRIBUTE)
                ?? $request->header('X-Discord-User-ID'))),
            'user_id' => $actor?->id,
            'method' => strtoupper($request->method()),
            'route' => $request->route()?->uri() ?? $request->path(),
            'request_hash' => $this->requestHash($request),
            'status' => DiscordCommandReceipt::STATUS_PROCESSING,
        ];

        try {
            return ['receipt' => DiscordCommandReceipt::query()->create($attributes), 'response' => null];
        } catch (QueryException $exception) {
            if (! $this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }
        }

        $receipt = DiscordCommandReceipt::query()
            ->where(function ($query) use ($attributes, $interactionId): void {
                $query->where('interaction_id', $interactionId);
                if ($attributes['connection_id'] !== null
                    && $attributes['connection_generation'] !== null
                    && $attributes['relay_idempotency_key'] !== null) {
                    $query->orWhere(function ($idempotency) use ($attributes): void {
                        $idempotency
                            ->where('connection_id', $attributes['connection_id'])
                            ->where('connection_generation', $attributes['connection_generation'])
                            ->where('relay_idempotency_key', $attributes['relay_idempotency_key']);
                    });
                }
            })
            ->firstOrFail();

        if ($receipt->interaction_id !== $attributes['interaction_id']
            || $receipt->request_hash !== $attributes['request_hash']
            || $receipt->user_id !== $actor?->id
            || $receipt->guild_id !== $attributes['guild_id']
            || $receipt->discord_user_id !== $attributes['discord_user_id']
            || $receipt->connection_id !== $attributes['connection_id']
            || $receipt->connection_generation !== $attributes['connection_generation']) {
            return ['receipt' => null, 'response' => $this->error(
                'discord_interaction_conflict',
                'This interaction ID was already used for a different request.',
                409,
            )];
        }

        if ($receipt->status === DiscordCommandReceipt::STATUS_COMPLETED) {
            $body = $receipt->response_body ?? ['data' => null];
            $body['meta'] = array_merge($body['meta'] ?? [], [
                'contract_version' => 1,
                'idempotent_replay' => true,
            ]);

            return [
                'receipt' => null,
                'response' => response()->json(
                    $body,
                    $receipt->response_status ?? 200,
                    ['X-Idempotent-Replay' => 'true'],
                ),
            ];
        }

        return ['receipt' => null, 'response' => $this->error(
            'discord_interaction_in_progress',
            'This interaction is already being processed.',
            409,
        )];
    }

    public function complete(DiscordCommandReceipt $receipt, Response $response): JsonResponse
    {
        $body = json_decode((string) $response->getContent(), true);
        $body = is_array($body) ? $body : ['data' => $body];
        $body['meta'] = array_merge($body['meta'] ?? [], [
            'contract_version' => 1,
            'idempotent_replay' => (bool) ($body['meta']['idempotent_replay'] ?? false),
        ]);

        $receipt->forceFill([
            'status' => DiscordCommandReceipt::STATUS_COMPLETED,
            'response_status' => $response->getStatusCode(),
            'response_body' => $body,
            'completed_at' => now(),
        ])->save();

        return response()->json($body, $response->getStatusCode());
    }

    public function fail(DiscordCommandReceipt $receipt): void
    {
        $receipt->forceFill([
            'status' => DiscordCommandReceipt::STATUS_FAILED,
            'failed_at' => now(),
        ])->save();
    }

    private function requestHash(Request $request): string
    {
        $payload = $request->all();
        $this->sortRecursively($payload);

        return hash('sha256', json_encode([
            'method' => strtoupper($request->method()),
            'path' => $request->path(),
            'payload' => $payload,
        ], JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function sortRecursively(array &$value): void
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as &$item) {
            if (is_array($item)) {
                $this->sortRecursively($item);
            }
        }
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        return in_array((string) ($exception->errorInfo[0] ?? ''), ['23000', '23505'], true);
    }

    private function error(string $error, string $message, int $status): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $error,
                'message' => $message,
            ],
            'meta' => ['contract_version' => 1],
        ], $status);
    }
}
