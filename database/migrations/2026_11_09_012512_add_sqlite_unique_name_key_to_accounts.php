<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Give SQLite the same "one active account name per nation" guard that MySQL
 * gets from 2025_03_16_044712_add_unique_constraint_to_accounts_name. SQLite
 * previously received a plain nullable column with no unique index.
 */
return new class extends Migration
{
    private const UNIQUE_INDEX = 'accounts_unique_name_key_unique';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        $this->renameDuplicateActiveAccounts();

        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('unique_name_key');
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->string('unique_name_key')
                ->virtualAs("CASE WHEN deleted_at IS NULL THEN name || '_' || nation_id END")
                ->nullable();
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->unique('unique_name_key', self::UNIQUE_INDEX);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        Schema::table('accounts', function (Blueprint $table) {
            $table->dropUnique(self::UNIQUE_INDEX);
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('unique_name_key');
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->string('unique_name_key')->nullable();
        });
    }

    /**
     * Without the index SQLite could hold several active accounts with the same
     * name for one nation. Keep the oldest name and suffix the later ones so the
     * unique index can be created.
     */
    private function renameDuplicateActiveAccounts(): void
    {
        $duplicates = DB::table('accounts')
            ->select('nation_id', 'name')
            ->whereNull('deleted_at')
            ->whereNotNull('nation_id')
            ->groupBy('nation_id', 'name')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            DB::table('accounts')
                ->where('nation_id', $duplicate->nation_id)
                ->where('name', $duplicate->name)
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->pluck('id')
                ->skip(1)
                ->each(fn (int $accountId) => DB::table('accounts')
                    ->where('id', $accountId)
                    ->update(['name' => "{$duplicate->name} #{$accountId}"]));
        }
    }
};
