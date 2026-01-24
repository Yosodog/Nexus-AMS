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
        Schema::table('transactions', function (Blueprint $table) {
            $table->timestamp('bank_processing_at')->nullable()->after('offshore_fulfillment_details');
            $table->timestamp('sent_at')->nullable()->after('bank_processing_at');
            $table->unsignedBigInteger('bank_record_id')->nullable()->after('sent_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn([
                'bank_processing_at',
                'sent_at',
                'bank_record_id',
            ]);
        });
    }
};
