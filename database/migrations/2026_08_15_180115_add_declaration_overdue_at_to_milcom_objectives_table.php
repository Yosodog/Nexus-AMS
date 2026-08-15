<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('milcom_objectives', function (Blueprint $table): void {
            $table->timestamp('declaration_overdue_at')->nullable()->after('deadline_at');
            $table->index(
                ['declaration_overdue_at', 'status'],
                'milcom_objective_declaration_overdue_idx',
            );
        });

        $openReviewObjectiveIds = DB::table('milcom_objectives')
            ->join('milcom_operations', 'milcom_operations.id', '=', 'milcom_objectives.operation_id')
            ->where('milcom_operations.type', 'counter')
            ->whereNotIn('milcom_operations.status', ['completed', 'archived'])
            ->whereIn('milcom_objectives.status', ['pending', 'review', 'blocked'])
            ->pluck('milcom_objectives.id');

        if ($openReviewObjectiveIds->isNotEmpty()) {
            DB::table('milcom_objectives')
                ->whereIn('id', $openReviewObjectiveIds)
                ->update([
                    'deadline_at' => null,
                    'declaration_overdue_at' => null,
                ]);

            DB::table('milcom_operations')
                ->whereIn(
                    'id',
                    DB::table('milcom_objectives')
                        ->whereIn('id', $openReviewObjectiveIds)
                        ->select('operation_id'),
                )
                ->whereNotIn('status', ['completed', 'archived'])
                ->update(['deadline_at' => null]);
        }

        DB::table('milcom_objectives')
            ->whereIn(
                'operation_id',
                DB::table('milcom_operations')
                    ->where('type', 'counter')
                    ->whereNotIn('status', ['completed', 'archived'])
                    ->select('id'),
            )
            ->whereIn('status', ['approved', 'dispatching', 'dispatched'])
            ->whereNotNull('deadline_at')
            ->where('deadline_at', '<=', now())
            ->whereNull('declaration_overdue_at')
            ->update(['declaration_overdue_at' => DB::raw('deadline_at')]);
    }

    public function down(): void
    {
        Schema::table('milcom_objectives', function (Blueprint $table): void {
            $table->dropIndex('milcom_objective_declaration_overdue_idx');
            $table->dropColumn('declaration_overdue_at');
        });
    }
};
