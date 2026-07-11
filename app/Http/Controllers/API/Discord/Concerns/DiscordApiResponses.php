<?php

namespace App\Http\Controllers\API\Discord\Concerns;

use Illuminate\Http\JsonResponse;

trait DiscordApiResponses
{
    /** @param array<string, mixed>|array<int, mixed>|object $data */
    protected function discordData(array|object $data, int $status = 200, array $meta = []): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'meta' => ['contract_version' => 1, ...$meta],
        ], $status);
    }

    protected function discordError(string $code, string $message, int $status, array $details = []): JsonResponse
    {
        return response()->json([
            'error' => array_filter([
                'code' => $code,
                'message' => $message,
                'details' => $details,
            ], fn (mixed $value): bool => $value !== []),
            'meta' => ['contract_version' => 1],
        ], $status);
    }
}
