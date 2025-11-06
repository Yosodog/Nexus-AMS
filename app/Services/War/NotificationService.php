<?php

namespace App\Services\War;

use App\Jobs\DispatchWarNotificationsJob;
use App\Models\WarCounter;
use App\Models\WarNotification;
use App\Models\WarPlan;
use App\Models\WarPlanAssignment;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Queues outbound notifications for war plan and counter events.
 *
 * Design Notes:
 * - We defer actual delivery to queued jobs so UI interactions stay responsive.
 * - Payloads include rich context to allow later integration with Discord bots or PW mail senders.
 * - Notification templates live in config/war.php and are resolved lazily for easier edits.
 */
class NotificationService
{
    /**
     * Queue notifications for published plan assignments.
     *
     * @param  Collection<int, WarPlanAssignment>  $assignments
     * @param  array{in_game?:bool, discord?:bool, create_room?:bool}  $channels
     */
    public function queuePlanPublishNotifications(
        WarPlan $plan,
        Collection $assignments,
        array $channels
    ): void {
        if ($channels['in_game'] ?? false) {
            $payload = [
                'event' => 'war.assignments.created',
                'plan_id' => $plan->id,
                'plan_name' => $plan->name,
                'assignments' => $assignments->map(function (WarPlanAssignment $assignment) {
                    $targetNation = $assignment->target?->nation;

                    return [
                        'friendly_nation_id' => $assignment->friendly_nation_id,
                        'friendly_name' => $assignment->friendlyNation?->leader_name,
                        'enemy_nation_id' => $targetNation?->id,
                        'enemy_name' => $targetNation?->nation_name,
                        'war_plan_target_id' => $assignment->war_plan_target_id,
                        'match_score' => $assignment->match_score,
                    ];
                })->all(),
                'template' => config('war.notifications.templates.plan_assignments'),
            ];

            $this->storeAndDispatch('war.assignments.created', $payload);
        }

        if ($channels['discord'] ?? false) {
            $this->storeAndDispatch('war.discord.create_room', [
                'plan_id' => $plan->id,
                'plan_name' => $plan->name,
                'suggested_room' => sprintf(
                    'war-%s',
                    Str::of($plan->name)->slug('-')
                ),
                'participants' => $assignments->pluck('friendly_nation_id')->unique()->all(),
                'template' => config('war.notifications.templates.discord_room'),
            ]);
        }
    }

    /**
     * Queue notifications for counter finalization.
     *
     * @param  Collection<int, \App\Models\WarCounterAssignment>  $assignments
     * @param  array{in_game?:bool, discord?:bool, create_room?:bool}  $channels
     */
    public function queueCounterFinalizedNotifications(
        WarCounter $counter,
        Collection $assignments,
        array $channels
    ): void {
        if ($channels['in_game'] ?? false) {
            $payload = [
                'event' => 'war.counter.finalized',
                'counter_id' => $counter->id,
                'aggressor' => [
                    'id' => $counter->aggressor_nation_id,
                    'name' => $counter->aggressor?->leader_name,
                ],
                'assignments' => $assignments->map(fn ($assignment) => [
                    'friendly_nation_id' => $assignment->friendly_nation_id,
                    'friendly_name' => $assignment->friendlyNation?->leader_name,
                    'match_score' => $assignment->match_score,
                ])->all(),
                'template' => config('war.notifications.templates.counter_finalized'),
            ];

            $this->storeAndDispatch('war.counter.finalized', $payload);
        }

        if ($channels['discord'] ?? false || $channels['create_room'] ?? false) {
            $this->storeAndDispatch('war.discord.create_room', [
                'counter_id' => $counter->id,
                'aggressor_name' => $counter->aggressor?->leader_name,
                'suggested_room' => sprintf(
                    'counter-%s',
                    Str::of($counter->aggressor?->leader_name ?? 'unknown')->slug('-')
                ),
                'participants' => $assignments->pluck('friendly_nation_id')->unique()->all(),
                'template' => config('war.notifications.templates.discord_room'),
            ]);
        }
    }

    /**
     * Persist payload and dispatch job.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function storeAndDispatch(string $eventType, array $payload): void
    {
        $record = WarNotification::query()->create([
            'event_type' => $eventType,
            'payload' => $payload,
            'status' => 'pending',
        ]);

        DispatchWarNotificationsJob::dispatch($record->id);
    }
}
