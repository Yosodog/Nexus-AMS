<?php

namespace App\Http\Controllers\API\Discord;

use App\Http\Controllers\API\Discord\Concerns\DiscordApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBlockadeReliefRequest;
use App\Models\BlockadeReliefRequest;
use App\Models\User;
use App\Services\BlockadeRelief\BlockadeReliefService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BlockadeReliefController extends Controller
{
    use DiscordApiResponses;

    public function index(Request $request, BlockadeReliefService $service): JsonResponse
    {
        return $this->discordData([
            'requests' => $service->requestsFor($this->actor($request))->map($this->payload(...)),
            'blockaded_wars' => $service->blockadedWarsFor($this->actor($request))->map(fn ($war): array => [
                'id' => $war->id,
                'label' => $this->warLabel($war, (int) $this->actor($request)->nation_id),
                'blockading_nation_id' => (int) $war->naval_blockade,
                'turns_left' => (int) $war->turns_left,
            ]),
        ]);
    }

    public function available(Request $request, BlockadeReliefService $service): JsonResponse
    {
        return $this->discordData([
            'requests' => $service->availableFor($this->actor($request))->map($this->payload(...)),
        ]);
    }

    public function store(StoreBlockadeReliefRequest $request, BlockadeReliefService $service): JsonResponse
    {
        $data = $request->validated();
        $reliefRequest = $service->create(
            $this->actor($request),
            (int) $data['war_id'],
            $data['note'] ?? null,
            (int) ($data['deadline_hours'] ?? 6),
        );

        return $this->discordData($this->payload($reliefRequest), 201);
    }

    public function claim(Request $request, BlockadeReliefRequest $blockadeReliefRequest, BlockadeReliefService $service): JsonResponse
    {
        return $this->discordData($this->payload(
            $service->claim($blockadeReliefRequest, $this->actor($request))
        ));
    }

    public function cancel(Request $request, BlockadeReliefRequest $blockadeReliefRequest, BlockadeReliefService $service): JsonResponse
    {
        return $this->discordData($this->payload(
            $service->cancel($blockadeReliefRequest, $this->actor($request))
        ));
    }

    /** @return array<string, mixed> */
    private function payload(BlockadeReliefRequest $request): array
    {
        $request->loadMissing(['requester', 'blockadingNation', 'claimer']);

        return [
            'id' => $request->id,
            'label' => sprintf(
                'Request #%d — %s vs %s',
                $request->id,
                $request->requester?->nation_name ?? 'Unknown member',
                $request->blockadingNation?->nation_name ?? 'Unknown blockader',
            ),
            'war_id' => $request->war_id,
            'status' => $request->status->value,
            'requester' => [
                'id' => $request->requester_nation_id,
                'name' => $request->requester?->nation_name,
            ],
            'blockader' => [
                'id' => $request->blockading_nation_id,
                'name' => $request->blockadingNation?->nation_name,
            ],
            'claimer' => $request->claimer ? [
                'id' => $request->claimer->id,
                'name' => $request->claimer->nation_name,
            ] : null,
            'deadline_at' => $request->deadline_at->toIso8601String(),
            'created_at' => $request->created_at->toIso8601String(),
            'deep_link_path' => '/defense/blockade-relief',
        ];
    }

    private function actor(Request $request): User
    {
        $actor = $request->attributes->get('discord_actor');
        abort_unless($actor instanceof User && $actor->nation !== null, 401, 'Discord actor context is missing.');

        return $actor;
    }

    private function warLabel(object $war, int $nationId): string
    {
        $opponent = (int) $war->att_id === $nationId ? $war->defender : $war->attacker;

        return sprintf('War #%d vs %s', $war->id, $opponent?->nation_name ?? 'Unknown nation');
    }
}
