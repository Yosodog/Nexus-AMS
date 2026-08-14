<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, string> */
    private const LEGACY_LANE_BY_ACTION = [
        'ALERT_DELIVERY_V1' => 'alerts',
        'WAR_ALERT' => 'alerts',
        'ALLIANCE_DEPARTURE' => 'alerts',
        'INACTIVITY_ALERT' => 'alerts',
        'BEIGE_ALERT' => 'alerts',
        'PRIVATE_NOTIFICATION' => 'alerts',
        'BLOCKADE_RELIEF_NOTIFICATION' => 'alerts',
        'APPLICATION_DISCORD_RECONCILE' => 'side_effects',
        'MEMBER_PROFILE_SYNC' => 'side_effects',
        'ALLIANCE_ROLE_REMOVAL' => 'side_effects',
        'CITY_TIER_SYNC' => 'side_effects',
        'WAR_ROOM_CREATE' => 'side_effects',
        'WAR_ROOM_ARCHIVE' => 'side_effects',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $unknownActions = DB::table('discord_queue')
            ->where('lane', 'legacy')
            ->whereNotIn('action', array_keys(self::LEGACY_LANE_BY_ACTION))
            ->distinct()
            ->orderBy('action')
            ->pluck('action')
            ->all();
        if ($unknownActions !== []) {
            throw new RuntimeException(
                'Cannot remove the legacy Discord queue lane until these actions are classified: '.implode(', ', $unknownActions),
            );
        }

        DB::table('discord_queue')
            ->whereIn('status', ['pending', 'processing'])
            ->where(function (Builder $query): void {
                $query->where('lane', 'legacy')
                    ->orWhereNull('connection_id')
                    ->orWhereNull('application_id')
                    ->orWhereNull('connection_generation')
                    ->orWhereNull('guild_id');
            })
            ->update([
                'status' => 'failed',
                'worker_id' => null,
                'lease_token' => null,
                'leased_until' => null,
                'last_error' => json_encode([
                    'code' => 'relay_v2_cutover',
                    'message' => 'The command was retired by the relay-v2-only queue cutover.',
                ], JSON_THROW_ON_ERROR),
                'completed_at' => now(),
                'updated_at' => now(),
            ]);

        foreach (self::LEGACY_LANE_BY_ACTION as $action => $lane) {
            DB::table('discord_queue')
                ->where('lane', 'legacy')
                ->where('action', $action)
                ->update(['lane' => $lane]);
        }

        $driver = DB::getDriverName();
        $boundScopeExpression = $driver === 'mysql'
            ? "concat(connection_id, ':', connection_generation)"
            : "connection_id || ':' || connection_generation";
        $historicalScopeExpression = $driver === 'mysql'
            ? "concat('historical:', id)"
            : "'historical:' || id";
        DB::table('discord_queue')
            ->whereNotNull('connection_id')
            ->where(function (Builder $query): void {
                $query->whereNull('dedupe_scope')->orWhere('dedupe_scope', 'legacy');
            })
            ->update(['dedupe_scope' => DB::raw($boundScopeExpression)]);
        DB::table('discord_queue')
            ->whereNull('connection_id')
            ->where(function (Builder $query): void {
                $query->whereNull('dedupe_scope')->orWhere('dedupe_scope', 'legacy');
            })
            ->update(['dedupe_scope' => DB::raw($historicalScopeExpression)]);

        Schema::table('discord_queue', function (Blueprint $table) {
            $table->string('lane', 24)->change();
            $table->string('dedupe_scope', 80)->change();
        });

        if ($driver === 'mysql') {
            DB::statement(<<<'SQL'
                ALTER TABLE discord_queue
                ADD CONSTRAINT discord_queue_v2_lane_check
                CHECK (lane IN ('alerts', 'digests', 'side_effects'))
            SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE discord_queue
                ADD CONSTRAINT discord_queue_v2_active_binding_check
                CHECK (
                    status NOT IN ('pending', 'processing')
                    OR (
                        connection_id IS NOT NULL
                        AND application_id IS NOT NULL
                        AND connection_generation IS NOT NULL
                        AND guild_id IS NOT NULL
                        AND dedupe_scope <> 'legacy'
                    )
                )
            SQL);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE discord_queue DROP CHECK discord_queue_v2_active_binding_check');
            DB::statement('ALTER TABLE discord_queue DROP CHECK discord_queue_v2_lane_check');
        }

        Schema::table('discord_queue', function (Blueprint $table) {
            $table->string('lane', 24)->default('legacy')->change();
            $table->string('dedupe_scope', 80)->default('legacy')->change();
        });
    }
};
