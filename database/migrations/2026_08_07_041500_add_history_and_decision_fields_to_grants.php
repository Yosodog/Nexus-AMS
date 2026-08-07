<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('grants', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(1)->after('slug');
        });

        Schema::table('grant_applications', function (Blueprint $table) {
            $table->string('program_name_snapshot')->nullable()->after('grant_id');
            $table->unsignedInteger('program_version_snapshot')->nullable()->after('program_name_snapshot');
            $table->string('decision_reason_code', 64)->nullable()->after('status');
            $table->text('decision_explanation')->nullable()->after('decision_reason_code');
            $table->text('decision_internal_note')->nullable()->after('decision_explanation');
            $table->foreignId('reviewed_by_user_id')
                ->nullable()
                ->after('decision_internal_note')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('submitted_at')->nullable()->after('pending_key');
            $table->timestamp('decided_at')->nullable()->after('denied_at');
            $table->timestamp('disbursed_at')->nullable()->after('decided_at');

            $table->index(['nation_id', 'created_at'], 'grant_apps_nation_created_idx');
            $table->index('created_at', 'grant_apps_created_idx');
            $table->index(['status', 'decided_at'], 'grant_apps_status_decided_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('grant_applications', function (Blueprint $table) {
            $table->dropForeign(['reviewed_by_user_id']);
            $table->dropIndex('grant_apps_nation_created_idx');
            $table->dropIndex('grant_apps_created_idx');
            $table->dropIndex('grant_apps_status_decided_idx');
            $table->dropColumn([
                'program_name_snapshot',
                'program_version_snapshot',
                'decision_reason_code',
                'decision_explanation',
                'decision_internal_note',
                'reviewed_by_user_id',
                'submitted_at',
                'decided_at',
                'disbursed_at',
            ]);
        });

        Schema::table('grants', function (Blueprint $table) {
            $table->dropColumn('version');
        });
    }
};
