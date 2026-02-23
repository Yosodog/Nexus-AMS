<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->decimal('scheduled_weekly_payment', 15, 2)->default(0)->after('weekly_interest_paid');
            $table->decimal('past_due_amount', 15, 2)->default(0)->after('scheduled_weekly_payment');
            $table->decimal('accrued_interest_due', 15, 2)->default(0)->after('past_due_amount');
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn([
                'scheduled_weekly_payment',
                'past_due_amount',
                'accrued_interest_due',
            ]);
        });
    }
};
