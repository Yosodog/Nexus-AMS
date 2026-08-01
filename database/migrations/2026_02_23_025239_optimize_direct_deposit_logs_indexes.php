<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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

        if (! $this->indexExists('direct_deposit_logs', 'ddl_nation_created_at_money_idx')) {
            Schema::table('direct_deposit_logs', function (Blueprint $table) {
                $table->index(['nation_id', 'created_at', 'money'], 'ddl_nation_created_at_money_idx');
            });
        }

        if (! $this->indexExists('direct_deposit_logs', 'ddl_account_created_at_idx')) {
            Schema::table('direct_deposit_logs', function (Blueprint $table) {
                $table->index(['account_id', 'created_at'], 'ddl_account_created_at_idx');
            });
        }

        if (! $this->indexExists('direct_deposit_logs', 'ddl_created_at_idx')) {
            Schema::table('direct_deposit_logs', function (Blueprint $table) {
                $table->index('created_at', 'ddl_created_at_idx');
            });
        }
    }

    public function down(): void
    {
        foreach (['ddl_nation_created_at_money_idx', 'ddl_account_created_at_idx', 'ddl_created_at_idx'] as $index) {
            if ($this->indexExists('direct_deposit_logs', $index)) {
                Schema::table('direct_deposit_logs', function (Blueprint $table) use ($index) {
                    $table->dropIndex($index);
                });
            }
        }

        if (! $this->indexExists('direct_deposit_logs', 'ddl_nation_created_at_idx')) {
            Schema::table('direct_deposit_logs', function (Blueprint $table) {
                $table->index(['nation_id', 'created_at'], 'ddl_nation_created_at_idx');
            });
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        return collect(Schema::getIndexes($table))
            ->contains(fn (array $index): bool => ($index['name'] ?? null) === $indexName);
    }
};
