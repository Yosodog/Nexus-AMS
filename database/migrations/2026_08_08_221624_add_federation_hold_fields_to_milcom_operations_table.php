<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('milcom_operations', function (Blueprint $table): void {
            $table->boolean('federation_action_required')->default(false)->after('failure_details');
            $table->string('federation_hold_reason', 64)->nullable()->after('federation_action_required');
            $table->timestamp('federation_held_at')->nullable()->after('federation_hold_reason');
            $table->timestamp('federation_detached_at')->nullable()->after('federation_held_at');
            $table->text('federation_resolution_reason')->nullable()->after('federation_detached_at');

            $table->index(
                ['federation_action_required', 'status', 'updated_at'],
                'milcom_federation_hold_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('milcom_operations', function (Blueprint $table): void {
            $table->dropIndex('milcom_federation_hold_idx');
            $table->dropColumn([
                'federation_action_required',
                'federation_hold_reason',
                'federation_held_at',
                'federation_detached_at',
                'federation_resolution_reason',
            ]);
        });
    }
};
