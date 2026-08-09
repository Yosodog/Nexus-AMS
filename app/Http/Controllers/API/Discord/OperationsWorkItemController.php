<?php

namespace App\Http\Controllers\API\Discord;

use App\Http\Controllers\API\Discord\Concerns\DiscordApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Middleware\VerifyDiscordInteraction;
use App\Http\Requests\Discord\DiscordOperationsWorkItemClaimRequest;
use App\Http\Requests\Discord\DiscordOperationsWorkItemIndexRequest;
use App\Http\Requests\Discord\DiscordOperationsWorkItemReleaseRequest;
use App\Http\Requests\Discord\DiscordOperationsWorkItemShowRequest;
use App\Http\Resources\Discord\OperationsWorkItemResource;
use App\Services\Discord\DiscordOperationsReadProvider;
use App\Services\StaffWorkQueue\OperationsCoordinationService;
use Illuminate\Http\JsonResponse;

final class OperationsWorkItemController extends Controller
{
    use DiscordApiResponses;

    public function __construct(
        private readonly DiscordOperationsReadProvider $provider,
        private readonly OperationsCoordinationService $coordination,
    ) {}

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
                'This work item was not found or you do not have access to it.',
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

    public function claim(DiscordOperationsWorkItemClaimRequest $request): JsonResponse
    {
        return $this->coordinationResponse($this->coordination->claim(
            actor: $request->actor(),
            workKey: $request->workKey(),
            occurrenceKey: (string) $request->validated('occurrence_key'),
            sourceRevision: (string) $request->validated('source_revision'),
            lockVersion: $request->validated('lock_version') === null
                ? null
                : (int) $request->validated('lock_version'),
            idempotencyKey: (string) $request->attributes->get(
                VerifyDiscordInteraction::INTERACTION_ATTRIBUTE,
            ),
        ));
    }

    public function release(DiscordOperationsWorkItemReleaseRequest $request): JsonResponse
    {
        return $this->coordinationResponse($this->coordination->release(
            actor: $request->actor(),
            workKey: $request->workKey(),
            occurrenceKey: (string) $request->validated('occurrence_key'),
            sourceRevision: (string) $request->validated('source_revision'),
            lockVersion: (int) $request->validated('lock_version'),
            idempotencyKey: (string) $request->attributes->get(
                VerifyDiscordInteraction::INTERACTION_ATTRIBUTE,
            ),
        ));
    }

    /** @param  array<string, mixed>  $result */
    private function coordinationResponse(array $result): JsonResponse
    {
        if (! (bool) $result['ok']) {
            return $this->discordError(
                (string) $result['code'],
                (string) $result['message'],
                (int) $result['status'],
                (array) ($result['details'] ?? []),
            );
        }

        return $this->discordData(
            (array) $result['data'],
            (int) $result['status'],
            [
                'provider' => 'nexus_operations',
                'projection_schema_version' => 2,
                ...(array) ($result['meta'] ?? []),
            ],
        );
    }
}
