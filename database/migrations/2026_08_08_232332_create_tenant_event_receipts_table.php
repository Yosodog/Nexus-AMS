<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tenant_event_receipts')) {
            if (! Schema::hasColumns('tenant_event_receipts', [
                'id',
                'delivery_id',
                'event_id',
                'contract_version',
                'event_type',
                'subject_key',
                'event_digest',
                'transport_nonce',
                'trace_id',
                'occurred_at',
                'published_at',
                'processed_at',
                'created_at',
                'updated_at',
            ])) {
                throw new LogicException(
                    'The tenant_event_receipts table is incomplete; repair it before resuming migration.',
                );
            }

            $this->ensureConstraintContract();

            return;
        }

        Schema::create('tenant_event_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('delivery_id', 26)->unique();
            $table->string('event_id', 191)->unique();
            $table->unsignedSmallInteger('contract_version');
            $table->string('event_type', 64);
            $table->string('subject_key', 191);
            $table->char('event_digest', 64);
            $table->char('transport_nonce', 32);
            $table->string('trace_id', 36);
            $table->timestamp('occurred_at');
            $table->timestamp('published_at');
            $table->timestamp('processed_at');
            $table->timestamps();

            $table->index(
                ['event_type', 'subject_key'],
                'tenant_event_receipts_subject_index',
            );
        });

        $this->ensureConstraintContract();
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_event_receipts');
    }

    private function ensureConstraintContract(): void
    {
        $indexes = Schema::getIndexes('tenant_event_receipts');
        $hasPrimaryKey = false;
        $hasDeliveryUnique = false;
        $hasEventUnique = false;
        $hasSubjectIndex = false;

        foreach ($indexes as $index) {
            $columns = $index['columns'] ?? [];
            $unique = ($index['unique'] ?? false) === true;

            if (($index['primary'] ?? false) === true && $columns === ['id']) {
                $hasPrimaryKey = true;
            }

            if ($columns === ['delivery_id'] && $unique) {
                $hasDeliveryUnique = true;
            }

            if ($columns === ['event_id'] && $unique) {
                $hasEventUnique = true;
            }

            if ($columns === ['event_type', 'subject_key']) {
                if ($unique) {
                    throw new LogicException('The tenant event subject index must not be unique.');
                }

                $hasSubjectIndex = true;
            }
        }

        if (! $hasPrimaryKey) {
            throw new LogicException('The tenant event receipt primary key is incompatible.');
        }

        Schema::table('tenant_event_receipts', function (Blueprint $table) use (
            $hasDeliveryUnique,
            $hasEventUnique,
            $hasSubjectIndex,
        ): void {
            if (! $hasDeliveryUnique) {
                $table->unique('delivery_id');
            }

            if (! $hasEventUnique) {
                $table->unique('event_id');
            }

            if (! $hasSubjectIndex) {
                $table->index(
                    ['event_type', 'subject_key'],
                    'tenant_event_receipts_subject_index',
                );
            }
        });
    }
};
