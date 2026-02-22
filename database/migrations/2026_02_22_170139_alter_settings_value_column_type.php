<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->longText('value')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('settings')
            ->whereRaw('CHAR_LENGTH(value) > 255')
            ->update([
                'value' => DB::raw('LEFT(value, 255)'),
            ]);

        Schema::table('settings', function (Blueprint $table) {
            $table->string('value', 255)->change();
        });
    }
};
