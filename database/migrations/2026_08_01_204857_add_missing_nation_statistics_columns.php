<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $nationColumns = collect([
            'money_looted',
            'total_infrastructure_destroyed',
            'total_infrastructure_lost',
        ])->reject(fn (string $column): bool => Schema::hasColumn('nations', $column));

        if ($nationColumns->isNotEmpty()) {
            Schema::table('nations', function (Blueprint $table) use ($nationColumns): void {
                foreach ($nationColumns as $column) {
                    $table->float($column)->default(0);
                }
            });
        }

        if (! Schema::hasColumn('nation_military', 'spy_attacks')) {
            Schema::table('nation_military', function (Blueprint $table): void {
                $table->unsignedInteger('spy_attacks')->default(0);
            });
        }
    }

    public function down(): void {}
};
