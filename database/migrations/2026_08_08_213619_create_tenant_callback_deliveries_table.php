<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tenant_callback_deliveries')) {
            if (! Schema::hasColumns('tenant_callback_deliveries', [
                'id',
                'callback_id',
                'tenant_id',
                'event_type',
                'subject_key',
                'release_id',
                'payload',
                'status',
                'attempt_count',
                'last_response_status',
                'last_failure_code',
                'next_attempt_at',
                'last_attempted_at',
                'occurred_at',
                'delivered_at',
                'created_at',
                'updated_at',
            ])) {
                throw new LogicException(
                    'The tenant_callback_deliveries table is incomplete; repair it before resuming migration.',
                );
            }

            $this->ensureConstraintContract();

            return;
        }

        Schema::create('tenant_callback_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->string('callback_id', 26)->unique();
            $table->string('tenant_id', 36);
            $table->string('event_type', 64);
            $table->string('subject_key', 64);
            $table->string('release_id', 64);
            $table->json('payload');
            $table->string('status', 24)->default('pending');
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->unsignedSmallInteger('last_response_status')->nullable();
            $table->string('last_failure_code', 64)->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['event_type', 'subject_key'],
                'tenant_callback_deliveries_effect_unique',
            );
            $table->index(
                ['status', 'next_attempt_at'],
                'tenant_callback_deliveries_due_index',
            );
            $table->index(
                ['status', 'last_attempted_at'],
                'tenant_callback_deliveries_stale_index',
            );
        });

        $this->ensureConstraintContract();
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_callback_deliveries');
    }

    private function ensureConstraintContract(): void
    {
        $indexes = Schema::getIndexes('tenant_callback_deliveries');
        $hasPrimaryKey = false;
        $hasCallbackIdUnique = false;
        $hasEffectUnique = false;
        $hasDueIndex = false;
        $hasStaleIndex = false;

        foreach ($indexes as $index) {
            $columns = $index['columns'] ?? [];
            $unique = ($index['unique'] ?? false) === true;

            if (($index['primary'] ?? false) === true && $columns === ['id']) {
                $hasPrimaryKey = true;
            }

            if ($columns === ['callback_id'] && $unique) {
                $hasCallbackIdUnique = true;
            }

            if ($columns === ['event_type', 'subject_key'] && $unique) {
                $hasEffectUnique = true;
            }

            if ($columns === ['status', 'next_attempt_at']) {
                if ($unique) {
                    throw new LogicException('The tenant callback due index must not be unique.');
                }

                $hasDueIndex = true;
            }

            if ($columns === ['status', 'last_attempted_at']) {
                if ($unique) {
                    throw new LogicException('The tenant callback stale index must not be unique.');
                }

                $hasStaleIndex = true;
            }
        }

        if (! $hasPrimaryKey) {
            throw new LogicException('The tenant callback delivery primary key is incompatible.');
        }

        Schema::table('tenant_callback_deliveries', function (Blueprint $table) use (
            $hasCallbackIdUnique,
            $hasEffectUnique,
            $hasDueIndex,
            $hasStaleIndex,
        ): void {
            if (! $hasCallbackIdUnique) {
                $table->unique('callback_id');
            }

            if (! $hasEffectUnique) {
                $table->unique(
                    ['event_type', 'subject_key'],
                    'tenant_callback_deliveries_effect_unique',
                );
            }

            if (! $hasDueIndex) {
                $table->index(
                    ['status', 'next_attempt_at'],
                    'tenant_callback_deliveries_due_index',
                );
            }

            if (! $hasStaleIndex) {
                $table->index(
                    ['status', 'last_attempted_at'],
                    'tenant_callback_deliveries_stale_index',
                );
            }
        });
    }
};
