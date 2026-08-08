<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bootstrap_redemptions')) {
            if (! Schema::hasColumns('bootstrap_redemptions', [
                'id',
                'token_hash',
                'tenant_id',
                'cloud_user_id',
                'action',
                'release_id',
                'alliance_id',
                'nation_id',
                'local_user_id',
                'mode',
                'claims_digest',
                'issued_at',
                'expires_at',
                'redeemed_at',
                'created_at',
                'updated_at',
            ])) {
                throw new LogicException(
                    'The bootstrap_redemptions table is incomplete; repair it before resuming migration.',
                );
            }

            $this->ensureConstraintContract();

            return;
        }

        Schema::create('bootstrap_redemptions', function (Blueprint $table): void {
            $table->id();
            $table->string('token_hash', 64)->unique();
            $table->string('tenant_id', 36);
            $table->string('cloud_user_id', 36);
            $table->string('action', 32);
            $table->string('release_id', 64);
            $table->unsignedBigInteger('alliance_id');
            $table->unsignedBigInteger('nation_id');
            $table->unsignedBigInteger('local_user_id')->nullable();
            $table->string('mode', 16)->nullable();
            $table->string('claims_digest', 64);
            $table->timestamp('issued_at');
            $table->timestamp('expires_at');
            $table->timestamp('redeemed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'action'],
                'bootstrap_redemptions_tenant_action_unique',
            );
            $table->index('cloud_user_id');
            $table->index('local_user_id');
        });

        $this->ensureConstraintContract();
    }

    public function down(): void
    {
        Schema::dropIfExists('bootstrap_redemptions');
    }

    private function ensureConstraintContract(): void
    {
        $indexes = Schema::getIndexes('bootstrap_redemptions');
        $hasPrimaryKey = false;
        $hasTokenUnique = false;
        $hasActionUnique = false;
        $hasCloudUserIndex = false;
        $hasLocalUserIndex = false;

        foreach ($indexes as $index) {
            $columns = $index['columns'] ?? [];
            $unique = ($index['unique'] ?? false) === true;

            if (($index['primary'] ?? false) === true && $columns === ['id']) {
                $hasPrimaryKey = true;
            }

            if ($columns === ['token_hash'] && $unique) {
                $hasTokenUnique = true;
            }

            if ($columns === ['tenant_id', 'action'] && $unique) {
                $hasActionUnique = true;
            }

            if ($columns === ['cloud_user_id']) {
                if ($unique) {
                    throw new LogicException('The bootstrap redemption cloud-user index must not be unique.');
                }

                $hasCloudUserIndex = true;
            }

            if ($columns === ['local_user_id']) {
                if ($unique) {
                    throw new LogicException('The bootstrap redemption local-user index must not be unique.');
                }

                $hasLocalUserIndex = true;
            }
        }

        if (! $hasPrimaryKey) {
            throw new LogicException('The bootstrap redemption primary key is incompatible.');
        }

        Schema::table('bootstrap_redemptions', function (Blueprint $table) use (
            $hasTokenUnique,
            $hasActionUnique,
            $hasCloudUserIndex,
            $hasLocalUserIndex,
        ): void {
            if (! $hasTokenUnique) {
                $table->unique('token_hash');
            }

            if (! $hasActionUnique) {
                $table->unique(
                    ['tenant_id', 'action'],
                    'bootstrap_redemptions_tenant_action_unique',
                );
            }

            if (! $hasCloudUserIndex) {
                $table->index('cloud_user_id');
            }

            if (! $hasLocalUserIndex) {
                $table->index('local_user_id');
            }
        });
    }
};
