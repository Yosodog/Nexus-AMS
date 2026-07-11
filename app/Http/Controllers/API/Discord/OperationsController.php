<?php

namespace App\Http\Controllers\API\Discord;

use App\Http\Controllers\API\Discord\Concerns\DiscordApiResponses;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\DiscordAssignmentResponse;
use App\Models\SpyAssignment;
use App\Models\User;
use App\Models\War;
use App\Models\WarCounter;
use App\Models\WarCounterAssignment;
use App\Models\WarPlanAssignment;
use App\Services\RaidFinderService;
use App\Services\WarSimulator\WarSimulatorDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OperationsController extends Controller
{
    use DiscordApiResponses;

    public function raids(Request $request, RaidFinderService $raidFinder): JsonResponse
    {
        $data = $request->validate([
            'nation_id' => ['nullable', 'integer', 'min:1'],
            'sort' => ['nullable', Rule::in(['value', 'cities', 'activity'])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $actor = $this->actor($request);
        $nationId = (int) ($data['nation_id'] ?? $actor->nation_id);

        if ($nationId !== (int) $actor->nation_id && ! $actor->hasPermission('view-raids')) {
            return $this->discordError('nation_not_owned', 'Raid searches are limited to the actor nation.', 403);
        }

        $targets = $raidFinder->findTargets($nationId);
        $sort = $data['sort'] ?? 'value';
        $targets = match ($sort) {
            'cities' => $targets->sortByDesc(fn ($target) => data_get($target, 'nation.num_cities', 0)),
            'activity' => $targets->sortByDesc(fn ($target) => data_get($target, 'nation.last_active', '')),
            default => $targets->sortByDesc('value'),
        };

        return $this->discordData($targets->take((int) ($data['limit'] ?? 20))->map(fn ($target): array => [
            'nation_id' => (int) data_get($target, 'nation.id'),
            'nation_name' => (string) data_get($target, 'nation.nation_name', ''),
            'leader_name' => (string) data_get($target, 'nation.leader_name', ''),
            'alliance_name' => data_get($target, 'nation.alliance.name'),
            'cities' => (int) data_get($target, 'nation.num_cities', 0),
            'score' => (float) data_get($target, 'nation.score', 0),
            'last_active' => data_get($target, 'nation.last_active'),
            'estimated_value' => (int) data_get($target, 'value', 0),
            'defensive_wars' => (int) data_get($target, 'defensive_wars', 0),
            'nation_url' => 'https://politicsandwar.com/nation/id='.data_get($target, 'nation.id'),
        ])->values()->all());
    }

    public function wars(Request $request): JsonResponse
    {
        $nationId = $this->actor($request)->nation_id;
        $wars = War::query()
            ->active()
            ->where(fn ($query) => $query->where('att_id', $nationId)->orWhere('def_id', $nationId))
            ->with(['attacker:id,nation_name,leader_name,alliance_id', 'defender:id,nation_name,leader_name,alliance_id'])
            ->orderByDesc('date')
            ->get();

        return $this->discordData($wars->map(fn (War $war): array => [
            'id' => $war->id,
            'role' => (int) $war->att_id === (int) $nationId ? 'attacker' : 'defender',
            'turns_left' => (int) $war->turns_left,
            'war_type' => $war->war_type,
            'attacker' => $this->nationSummary($war->attacker),
            'defender' => $this->nationSummary($war->defender),
            'war_url' => 'https://politicsandwar.com/nation/war/timeline/war='.$war->id,
        ])->all());
    }

    public function warCounter(Request $request): JsonResponse
    {
        $data = $request->validate(['nation_id' => ['required', 'integer', 'min:1']]);
        $actor = $this->actor($request);
        $query = WarCounter::query()
            ->where('aggressor_nation_id', $data['nation_id'])
            ->whereIn('status', WarCounter::openStatuses())
            ->with(['aggressor:id,nation_name,leader_name,alliance_id', 'assignments.friendlyNation:id,nation_name,leader_name,alliance_id']);

        if (! $actor->hasPermission('manage-war-room')) {
            $query->whereHas('assignments', fn ($assignment) => $assignment->where('friendly_nation_id', $actor->nation_id));
        }

        $counter = $query->latest()->first();
        if (! $counter) {
            return $this->discordError('counter_not_found', 'No accessible active counter was found for that nation.', 404);
        }

        return $this->discordData([
            'items' => [[
                'id' => $counter->id,
                'status' => $counter->status,
                'type' => $counter->war_declaration_type,
                'target' => $this->nationSummary($counter->aggressor),
                'team_size' => $counter->team_size,
                'assigned_nation_ids' => $counter->assignments->pluck('friendly_nation_id')->map(fn ($id) => (int) $id)->all(),
                'deep_link_path' => '/admin/war-counters/'.$counter->id,
            ]],
        ]);
    }

    public function warSimulation(Request $request, War $war, WarSimulatorDataService $dataService): JsonResponse
    {
        $actor = $this->actor($request);
        $isParticipant = in_array((int) $actor->nation_id, [(int) $war->att_id, (int) $war->def_id], true);
        if (! $isParticipant && ! $actor->hasPermission('view-raids')) {
            return $this->discordError('forbidden', 'You do not have permission to inspect this war.', 403);
        }

        $payload = $dataService->buildWarPayload($war);
        $attacker = data_get($payload, 'attacker.nation_name', 'Attacker');
        $defender = data_get($payload, 'defender.nation_name', 'Defender');

        return $this->discordData([
            'war_id' => $war->id,
            'summary' => sprintf(
                '%s vs %s · %s war · air: %s · ground: %s · blockade: %s',
                $attacker,
                $defender,
                data_get($payload, 'context.war_type', 'ordinary'),
                data_get($payload, 'context.air_superiority_owner', 'none'),
                data_get($payload, 'context.ground_control_owner', 'none'),
                data_get($payload, 'context.blockade_owner', 'none'),
            ),
            'context' => $payload['context'],
            'deep_link_path' => '/defense/war-simulators?war='.$war->id,
        ]);
    }

    public function warAssignments(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $responses = DiscordAssignmentResponse::query()->where('user_id', $actor->id)->get()
            ->keyBy(fn (DiscordAssignmentResponse $response): string => $response->assignment_type.':'.$response->assignment_id);

        $plans = WarPlanAssignment::query()
            ->where('friendly_nation_id', $actor->nation_id)
            ->whereHas('plan', fn ($query) => $query->whereIn('status', ['planning', 'active']))
            ->with(['plan:id,name,status,assignments_published_at', 'target.nation:id,nation_name,leader_name,alliance_id'])
            ->latest()
            ->get()
            ->map(fn (WarPlanAssignment $assignment): array => $this->planAssignment($assignment, $responses->get('plan:'.$assignment->id)));

        $counters = WarCounterAssignment::query()
            ->where('friendly_nation_id', $actor->nation_id)
            ->whereHas('counter', fn ($query) => $query->whereIn('status', ['draft', 'active']))
            ->with(['counter.aggressor:id,nation_name,leader_name,alliance_id'])
            ->latest()
            ->get()
            ->map(fn (WarCounterAssignment $assignment): array => $this->counterAssignment($assignment, $responses->get('counter:'.$assignment->id)));

        return $this->discordData($plans->concat($counters)->sortByDesc('created_at')->values()->all());
    }

    public function respondToWarAssignment(Request $request, string $type, int $id): JsonResponse
    {
        if (! in_array($type, ['plan', 'counter'], true)) {
            return $this->discordError('invalid_assignment_type', 'Assignment type must be plan or counter.', 422);
        }

        $data = $request->validate([
            'response' => ['required', Rule::in(['acknowledged', 'unavailable'])],
            'reason' => ['nullable', 'string', 'max:500', Rule::requiredIf($request->input('response') === 'unavailable')],
        ]);
        $actor = $this->actor($request);
        $model = $type === 'plan' ? WarPlanAssignment::class : WarCounterAssignment::class;
        $assignment = $model::query()->whereKey($id)->where('friendly_nation_id', $actor->nation_id)->first();

        if (! $assignment) {
            return $this->discordError('assignment_not_found', 'No assignment was found for this actor.', 404);
        }

        $response = DiscordAssignmentResponse::query()->updateOrCreate(
            ['assignment_type' => $type, 'assignment_id' => $id, 'user_id' => $actor->id],
            [
                'nation_id' => $actor->nation_id,
                'response' => $data['response'],
                'reason' => $data['response'] === 'unavailable' ? $data['reason'] : null,
                'discord_interaction_id' => $request->header('X-Discord-Interaction-ID'),
            ],
        );

        return $this->discordData([
            'assignment_type' => $type,
            'assignment_id' => $id,
            'response' => $response->response,
            'reason' => $response->reason,
            'responded_at' => $response->updated_at->toIso8601String(),
        ]);
    }

    public function spyAssignments(Request $request): JsonResponse
    {
        $assignments = SpyAssignment::query()
            ->where('attacker_nation_id', $this->actor($request)->nation_id)
            ->whereHas('round.campaign', fn ($query) => $query->whereNotIn('status', ['completed', 'cancelled']))
            ->with(['round.campaign:id,name,status', 'defender:id,nation_name,leader_name,alliance_id'])
            ->latest()
            ->limit(100)
            ->get();

        return $this->discordData($assignments->map(fn (SpyAssignment $assignment): array => [
            'id' => $assignment->id,
            'status' => $assignment->status->value,
            'operation' => $assignment->op_type->value,
            'safety_level' => (int) $assignment->safety_level,
            'calculated_odds' => $assignment->calculated_odds,
            'target' => $this->nationSummary($assignment->defender),
            'campaign' => [
                'id' => $assignment->round?->campaign?->id,
                'name' => $assignment->round?->campaign?->name,
                'round' => $assignment->round?->round_number,
            ],
            'espionage_url' => 'https://politicsandwar.com/nation/espionage/eid='.$assignment->defender_nation_id,
        ])->all());
    }

    public function applications(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $discordId = $request->attributes->get('discord_account')?->discord_id;
        $applications = Application::query()
            ->where(function ($query) use ($actor, $discordId): void {
                $query->where('nation_id', $actor->nation_id);
                if (is_string($discordId) && $discordId !== '') {
                    $query->orWhere('discord_user_id', $discordId);
                }
            })
            ->latest()
            ->limit(25)
            ->get();

        return $this->discordData($applications->map(fn (Application $application): array => [
            'id' => $application->id,
            'status' => $application->status->value,
            'created_at' => $application->created_at->toIso8601String(),
            'updated_at' => $application->updated_at->toIso8601String(),
            'deep_link_path' => '/apply',
        ])->all());
    }

    private function planAssignment(WarPlanAssignment $assignment, ?DiscordAssignmentResponse $response): array
    {
        return [
            'type' => 'plan',
            'id' => $assignment->id,
            'status' => $assignment->status,
            'source' => ['id' => $assignment->plan?->id, 'name' => $assignment->plan?->name],
            'target' => $this->nationSummary($assignment->target?->nation),
            'response' => $response?->only(['response', 'reason', 'updated_at']),
            'created_at' => optional($assignment->created_at)->toIso8601String(),
        ];
    }

    private function counterAssignment(WarCounterAssignment $assignment, ?DiscordAssignmentResponse $response): array
    {
        return [
            'type' => 'counter',
            'id' => $assignment->id,
            'status' => $assignment->status,
            'source' => ['id' => $assignment->counter?->id, 'name' => 'War counter #'.$assignment->counter?->id],
            'target' => $this->nationSummary($assignment->counter?->aggressor),
            'response' => $response?->only(['response', 'reason', 'updated_at']),
            'created_at' => optional($assignment->created_at)->toIso8601String(),
        ];
    }

    private function nationSummary(?object $nation): ?array
    {
        return $nation ? [
            'id' => $nation->id,
            'nation_name' => $nation->nation_name,
            'leader_name' => $nation->leader_name,
            'alliance_id' => $nation->alliance_id,
            'nation_url' => 'https://politicsandwar.com/nation/id='.$nation->id,
        ] : null;
    }

    private function actor(Request $request): User
    {
        $actor = $request->attributes->get('discord_actor');
        abort_unless($actor instanceof User && $actor->nation !== null, 401, 'Discord actor context is missing.');

        return $actor;
    }
}
