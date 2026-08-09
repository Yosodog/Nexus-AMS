<?php

namespace App\Http\Controllers\API\Discord;

use App\Http\Controllers\API\Discord\Concerns\DiscordApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Discord\DiscordOperationsWorkItemIndexRequest;
use App\Http\Requests\Discord\DiscordOperationsWorkItemShowRequest;
use App\Http\Resources\Discord\OperationsWorkItemResource;
use App\Services\Discord\DiscordOperationsReadProvider;
use Illuminate\Http\JsonResponse;

final class OperationsWorkItemController extends Controller
{
    use DiscordApiResponses;

    public function __construct(private readonly DiscordOperationsReadProvider $provider) {}

    public function index(DiscordOperationsWorkItemIndexRequest $request): JsonResponse
    {
        $page = $this->provider->paginate($request->actor(), $request->validated());

        return $this->discordData(
            OperationsWorkItemResource::collection($page['items'])->resolve($request),
            meta: $page['meta'],
        );
    }

    public function show(DiscordOperationsWorkItemShowRequest $request): JsonResponse
    {
        $item = $this->provider->find($request->actor(), $request->workKey());

        if ($item === null) {
            return $this->discordError(
                'not_found',
                'The Operations work item was not found or is not visible to this actor.',
                404,
            );
        }

        return $this->discordData(
            (new OperationsWorkItemResource($item))->resolve($request),
            meta: [
                'provider' => 'nexus_operations',
                'projection_schema_version' => 2,
            ],
        );
    }
}
