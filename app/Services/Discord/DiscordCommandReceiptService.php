<?php

namespace App\Services\Discord;

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
    public function claim(Request $request, User $actor): array
    {
        $interactionId = trim((string) $request->header('X-Discord-Interaction-ID'));

        if (preg_match('/^\d{1,32}$/', $interactionId) !== 1) {
            return ['receipt' => null, 'response' => $this->error(
                'invalid_discord_interaction',
                'A valid Discord interaction ID is required for mutations.',
                400,
            )];
        }

        $attributes = [
            'interaction_id' => $interactionId,
            'guild_id' => trim((string) $request->header('X-Discord-Guild-ID')),
            'discord_user_id' => trim((string) $request->header('X-Discord-User-ID')),
            'user_id' => $actor->id,
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
            ->where('interaction_id', $interactionId)
            ->firstOrFail();

        if ($receipt->request_hash !== $attributes['request_hash']
            || $receipt->user_id !== $actor->id
            || $receipt->guild_id !== $attributes['guild_id']) {
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
            'idempotent_replay' => false,
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
