<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('process_heartbeats')) {
            if (! Schema::hasColumns('process_heartbeats', [
                'role',
                'release_id',
                'last_seen_at',
                'created_at',
                'updated_at',
            ])) {
                throw new LogicException(
                    'The process_heartbeats table is incomplete; repair it before resuming migration.',
                );
            }

            $this->ensureConstraintContract();

            return;
        }

        Schema::create('process_heartbeats', function (Blueprint $table): void {
            $table->string('role', 32)->primary();
            $table->string('release_id', 64);
            $table->timestamp('last_seen_at')->index();
            $table->timestamps();
        });

        $this->ensureConstraintContract();
    }

    public function down(): void
    {
        Schema::dropIfExists('process_heartbeats');
    }

    private function ensureConstraintContract(): void
    {
        $indexes = Schema::getIndexes('process_heartbeats');
        $hasRolePrimaryKey = false;
        $hasLastSeenIndex = false;

        foreach ($indexes as $index) {
            $columns = $index['columns'] ?? [];

            if (($index['primary'] ?? false) === true && $columns === ['role']) {
                $hasRolePrimaryKey = true;
            }

            if ($columns === ['last_seen_at']) {
                if (($index['unique'] ?? false) === true) {
                    throw new LogicException(
                        'The process_heartbeats last_seen_at column must not be unique.',
                    );
                }

                $hasLastSeenIndex = true;
            }
        }

        if (! $hasRolePrimaryKey) {
            throw new LogicException(
                'The process_heartbeats table has an incompatible primary key.',
            );
        }

        if (! $hasLastSeenIndex) {
            Schema::table('process_heartbeats', function (Blueprint $table): void {
                $table->index('last_seen_at');
            });
        }
    }
};
