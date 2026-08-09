<?php

namespace App\Http\Resources\Discord;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class AlertActivityResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $activity */
        $activity = $this->resource;
        $eventKey = (string) ($activity['event_key'] ?? 'alert');

        return [
            ...$activity,
            'event_label' => self::eventLabel($eventKey),
        ];
    }

    public static function eventLabel(string $eventKey): string
    {
        return Str::ucfirst(Str::lower(Str::headline(str_replace('.', ' ', $eventKey))));
    }
}
