<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::table('operations_work_coordination')
                ->where('source_type', 'member_transfers')
                ->whereNull('closed_at')
                ->orderBy('id')
                ->chunkById(500, function (Collection $workItems): void {
                    $closedAt = now();
                    $events = $workItems->map(fn (object $workItem): array => [
                        'coordination_id' => $workItem->id,
                        'work_key' => $workItem->work_key,
                        'occurrence_key' => $workItem->occurrence_key,
                        'source_type' => $workItem->source_type,
                        'team_key' => 'internal_affairs',
                        'event_type' => 'closed',
                        'actor_user_id' => null,
                        'subject_user_id' => $workItem->assignee_user_id,
                        'correlation_id' => null,
                        'idempotency_key' => 'source-retired:member_transfers:'.$workItem->id,
                        'metadata' => json_encode(['reason' => 'source_retired'], JSON_THROW_ON_ERROR),
                        'occurred_at' => $closedAt,
                        'created_at' => $closedAt,
                        'updated_at' => $closedAt,
                    ])->all();

                    DB::table('operations_work_events')->insert($events);
                    DB::table('operations_work_coordination')
                        ->whereIn('id', $workItems->pluck('id'))
                        ->update([
                            'assignee_user_id' => null,
                            'assigned_by_user_id' => null,
                            'assigned_at' => null,
                            'assignment_expires_at' => null,
                            'last_activity_at' => $closedAt,
                            'closed_at' => $closedAt,
                            'active_key' => null,
                            'lock_version' => DB::raw('lock_version + 1'),
                            'updated_at' => $closedAt,
                        ]);
                });
        });
    }

    /**
     * Retired work items cannot be reopened safely.
     */
    public function down(): void {}
};
