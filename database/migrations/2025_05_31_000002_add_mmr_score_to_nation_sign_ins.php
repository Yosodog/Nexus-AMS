<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up() {
        Schema::table('nation_sign_ins', function (Blueprint $table) {
            $table->unsignedTinyInteger('mmr_score')->nullable()->after('id');
        });
    }
    public function down() {
        Schema::table('nation_sign_ins', function (Blueprint $table) {
            $table->dropColumn('mmr_score');
        });
    }
};
