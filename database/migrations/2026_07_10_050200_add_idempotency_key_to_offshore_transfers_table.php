<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offshore_transfers', function (Blueprint $table) {
            $table->string('idempotency_key')->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('offshore_transfers', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
    }
};
