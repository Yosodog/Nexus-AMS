<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('offshore_fulfillment_status')->nullable()->after('pending_reason');
            $table->text('offshore_fulfillment_message')->nullable()->after('offshore_fulfillment_status');
            $table->json('offshore_fulfillment_details')->nullable()->after('offshore_fulfillment_message');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn([
                'offshore_fulfillment_status',
                'offshore_fulfillment_message',
                'offshore_fulfillment_details',
            ]);
        });
    }
};
