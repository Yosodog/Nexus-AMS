<?php

namespace App\Http\Resources\Discord;

use App\Models\MilcomAssignmentResponse;
use App\Services\Discord\DiscordMilcomAssignmentResponseService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class MilcomAssignmentResponseResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var MilcomAssignmentResponse $response */
        $response = $this->resource;

        return [
            'assignment_type' => DiscordMilcomAssignmentResponseService::ASSIGNMENT_TYPE,
            'assignment_id' => (int) $response->assignment_id,
            'response' => (string) $response->response,
            'reason' => filled($response->reason) ? (string) $response->reason : null,
            'responded_at' => $response->responded_at?->toIso8601String(),
        ];
    }
}
