<?php

namespace App\Http\Resources\Discord;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AlertDeliveryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $delivery */
        $delivery = $this->resource;

        return $delivery;
    }
}
