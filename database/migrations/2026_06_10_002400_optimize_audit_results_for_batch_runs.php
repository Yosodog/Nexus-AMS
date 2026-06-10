<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('audit_results', 'target_key')) {
            Schema::table('audit_results', function (Blueprint $table): void {
                $table->string('target_key')->nullable()->after('target_type');
            });
        }

        $this->deleteRowsWithoutConcreteTargets();
        $this->backfillTargetKeys();
        $this->deleteDuplicateTargets();

        if (! Schema::hasIndex('audit_results', 'audit_results_rule_target_unique')) {
            Schema::table('audit_results', function (Blueprint $table): void {
                $table->unique(['audit_rule_id', 'target_type', 'target_key'], 'audit_results_rule_target_unique');
            });
        }

        if (Schema::hasIndex('audit_results', 'audit_results_unique_target')) {
            Schema::table('audit_results', function (Blueprint $table): void {
                $table->dropUnique('audit_results_unique_target');
            });
        }

        if (! Schema::hasIndex('audit_rules', 'audit_rules_enabled_target_idx')) {
            Schema::table('audit_rules', function (Blueprint $table): void {
                $table->index(['enabled', 'target_type'], 'audit_rules_enabled_target_idx');
            });
        }

        if (! Schema::hasIndex('nations', 'nations_member_scope_idx')) {
            Schema::table('nations', function (Blueprint $table): void {
                $table->index(['alliance_id', 'alliance_position', 'vacation_mode_turns'], 'nations_member_scope_idx');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasIndex('nations', 'nations_member_scope_idx')) {
            Schema::table('nations', function (Blueprint $table): void {
                $table->dropIndex('nations_member_scope_idx');
            });
        }

        if (Schema::hasIndex('audit_rules', 'audit_rules_enabled_target_idx')) {
            Schema::table('audit_rules', function (Blueprint $table): void {
                $table->dropIndex('audit_rules_enabled_target_idx');
            });
        }

        if (
            Schema::hasIndex('audit_results', 'audit_results_rule_target_unique')
            && ! Schema::hasIndex('audit_results', 'audit_results_rollback_audit_rule_idx')
        ) {
            Schema::table('audit_results', function (Blueprint $table): void {
                $table->index('audit_rule_id', 'audit_results_rollback_audit_rule_idx');
            });
        }

        if (Schema::hasIndex('audit_results', 'audit_results_rule_target_unique')) {
            Schema::table('audit_results', function (Blueprint $table): void {
                $table->dropUnique('audit_results_rule_target_unique');
            });
        }

        if (Schema::hasColumn('audit_results', 'target_key')) {
            Schema::table('audit_results', function (Blueprint $table): void {
                $table->dropColumn('target_key');
            });
        }

        if (! Schema::hasIndex('audit_results', 'audit_results_unique_target')) {
            Schema::table('audit_results', function (Blueprint $table): void {
                $table->unique(['audit_rule_id', 'target_type', 'nation_id', 'city_id'], 'audit_results_unique_target');
            });
        }

        if (Schema::hasIndex('audit_results', 'audit_results_rollback_audit_rule_idx')) {
            Schema::table('audit_results', function (Blueprint $table): void {
                $table->dropIndex('audit_results_rollback_audit_rule_idx');
            });
        }
    }

    private function deleteRowsWithoutConcreteTargets(): void
    {
        DB::table('audit_results')
            ->where('target_type', 'nation')
            ->whereNull('nation_id')
            ->delete();

        DB::table('audit_results')
            ->where('target_type', 'city')
            ->whereNull('city_id')
            ->delete();
    }

    private function backfillTargetKeys(): void
    {
        DB::table('audit_results')
            ->where('target_type', 'nation')
            ->whereNotNull('nation_id')
            ->orderBy('id')
            ->chunkById(500, function ($results): void {
                foreach ($results as $result) {
                    DB::table('audit_results')
                        ->where('id', $result->id)
                        ->update(['target_key' => "nation:{$result->nation_id}"]);
                }
            });

        DB::table('audit_results')
            ->where('target_type', 'city')
            ->whereNotNull('city_id')
            ->orderBy('id')
            ->chunkById(500, function ($results): void {
                foreach ($results as $result) {
                    DB::table('audit_results')
                        ->where('id', $result->id)
                        ->update(['target_key' => "city:{$result->city_id}"]);
                }
            });

        DB::table('audit_results')
            ->whereNull('target_key')
            ->delete();
    }

    private function deleteDuplicateTargets(): void
    {
        $duplicates = DB::table('audit_results')
            ->select('audit_rule_id', 'target_type', 'target_key', DB::raw('MIN(id) as keep_id'))
            ->groupBy('audit_rule_id', 'target_type', 'target_key')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('keep_id')
            ->get();

        foreach ($duplicates as $duplicate) {
            DB::table('audit_results')
                ->where('audit_rule_id', $duplicate->audit_rule_id)
                ->where('target_type', $duplicate->target_type)
                ->where('target_key', $duplicate->target_key)
                ->where('id', '!=', $duplicate->keep_id)
                ->delete();
        }
    }
};
