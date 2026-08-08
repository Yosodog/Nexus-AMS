<?php

namespace App\Http\Controllers\API\Discord;

use App\Http\Controllers\API\Discord\Concerns\DiscordApiResponses;
use App\Http\Controllers\Controller;
use App\Services\Alerts\AlertEventCatalog;
use Illuminate\Http\JsonResponse;

final class AlertRendererManifestController extends Controller
{
    use DiscordApiResponses;

    public function __invoke(AlertEventCatalog $catalog): JsonResponse
    {
        return $this->discordData($catalog->rendererManifest());
    }
}
