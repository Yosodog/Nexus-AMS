<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if ($this->indexExists('direct_deposit_logs', 'ddl_nation_created_at_idx')) {
            Schema::table('direct_deposit_logs', function (Blueprint $table) {
                $table->dropIndex('ddl_nation_created_at_idx');
            });
        }

        Schema::table('direct_deposit_logs', function (Blueprint $table) {
            $table->index(['nation_id', 'created_at', 'money'], 'ddl_nation_created_at_money_idx');
            $table->index(['account_id', 'created_at'], 'ddl_account_created_at_idx');
            $table->index('created_at', 'ddl_created_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('direct_deposit_logs', function (Blueprint $table) {
            $table->dropIndex('ddl_nation_created_at_money_idx');
            $table->dropIndex('ddl_account_created_at_idx');
            $table->dropIndex('ddl_created_at_idx');
        });

        if (! $this->indexExists('direct_deposit_logs', 'ddl_nation_created_at_idx')) {
            Schema::table('direct_deposit_logs', function (Blueprint $table) {
                $table->index(['nation_id', 'created_at'], 'ddl_nation_created_at_idx');
            });
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $databaseName = DB::getDatabaseName();
        $matches = DB::select(
            'SELECT 1 FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ? LIMIT 1',
            [$databaseName, $table, $indexName]
        );

        return $matches !== [];
    }
};
