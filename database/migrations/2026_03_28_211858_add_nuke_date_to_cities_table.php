<?php

use App\Support\Database\WorldSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        WorldSchema::table('cities', function (Blueprint $table) {
            $table->date('nuke_date')->nullable()->after('date');
        });
    }

    public function down(): void
    {
        WorldSchema::table('cities', function (Blueprint $table) {
            $table->dropColumn('nuke_date');
        });
    }
};
