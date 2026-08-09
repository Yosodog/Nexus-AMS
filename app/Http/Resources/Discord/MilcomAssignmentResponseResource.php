<?php

namespace App\Http\Resources\Discord;

use App\Models\DiscordAssignmentResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class MilcomAssignmentResponseResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var DiscordAssignmentResponse $response */
        $response = $this->resource;

        return [
            'assignment_type' => (string) $response->assignment_type,
            'assignment_id' => (int) $response->assignment_id,
            'response' => (string) $response->response,
            'reason' => filled($response->reason) ? (string) $response->reason : null,
            'responded_at' => $response->updated_at?->toIso8601String(),
        ];
    }
}
