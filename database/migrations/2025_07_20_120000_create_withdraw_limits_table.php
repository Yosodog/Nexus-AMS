<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('withdraw_limits', function (Blueprint $table) {
            $table->id();
            $table->string('resource')->unique();
            $table->decimal('daily_limit', 18, 2)->default(0);
            $table->timestamps();
        });

        $now = now();
        $resources = [
            'money',
            'coal',
            'oil',
            'uranium',
            'iron',
            'bauxite',
            'lead',
            'gasoline',
            'munitions',
            'steel',
            'aluminum',
            'food',
        ];

        DB::table('withdraw_limits')->insert(array_map(static function (string $resource) use ($now) {
            return [
                'resource' => $resource,
                'daily_limit' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }, $resources));
    }

    public function down(): void
    {
        Schema::dropIfExists('withdraw_limits');
    }
};
