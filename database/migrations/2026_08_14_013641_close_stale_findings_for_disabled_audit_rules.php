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
            DB::table('audit_results')
                ->join('audit_rules', 'audit_results.audit_rule_id', '=', 'audit_rules.id')
                ->where('audit_rules.enabled', false)
                ->select([
                    'audit_results.id',
                    'audit_results.audit_rule_id',
                    'audit_results.rule_revision',
                    'audit_results.target_type',
                    'audit_results.target_key',
                    'audit_results.nation_id',
                    'audit_results.city_id',
                    'audit_rules.name as rule_name',
                    'audit_rules.priority as rule_priority',
                    'audit_rules.revision as current_rule_revision',
                ])
                ->chunkById(500, function (Collection $findings): void {
                    $occurredAt = now();
                    $events = $findings->map(fn (object $finding): array => [
                        'audit_result_id' => $finding->id,
                        'audit_rule_id' => $finding->audit_rule_id,
                        'target_type' => $finding->target_type,
                        'target_key' => $finding->target_key,
                        'nation_id' => $finding->nation_id,
                        'city_id' => $finding->city_id,
                        'actor_user_id' => null,
                        'event_type' => 'rule_disabled',
                        'metadata' => json_encode([
                            'reason' => 'disabled_rule_cleanup',
                            'rule_revision' => (int) $finding->rule_revision,
                            'rule_snapshot' => [
                                'name' => $finding->rule_name,
                                'priority' => $finding->rule_priority,
                                'revision' => (int) $finding->current_rule_revision,
                            ],
                        ], JSON_THROW_ON_ERROR),
                        'occurred_at' => $occurredAt,
                        'created_at' => $occurredAt,
                        'updated_at' => $occurredAt,
                    ])->all();

                    DB::table('audit_result_events')->insert($events);
                    DB::table('audit_results')->whereIn('id', $findings->pluck('id'))->delete();
                }, 'audit_results.id', 'id');

            DB::table('audit_rules')
                ->where('enabled', false)
                ->update(['last_match_count' => 0]);
        });
    }

    /**
     * Historical finding cleanup cannot be reconstructed safely.
     */
    public function down(): void {}
};
