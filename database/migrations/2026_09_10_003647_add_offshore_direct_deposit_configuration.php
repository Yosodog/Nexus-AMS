<?php

use App\Support\Database\WorldReference;
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
        Schema::table('offshores', function (Blueprint $table) {
            $table->boolean('direct_deposit_enabled')->default(false)->after('enabled');
            $table->unsignedInteger('direct_deposit_tax_id')->nullable()->after('direct_deposit_enabled');
            $table->unsignedInteger('direct_deposit_fallback_tax_id')->nullable()->after('direct_deposit_tax_id');
        });

        Schema::table('direct_deposit_enrollments', function (Blueprint $table) {
            $table->foreignId('offshore_id')
                ->nullable()
                ->after('nation_id')
                ->constrained('offshores')
                ->restrictOnDelete();
            WorldReference::alliance($table, 'alliance_id')
                ->nullable()
                ->after('offshore_id');
            $table->unsignedInteger('direct_deposit_tax_id')->nullable()->after('account_id');
            $table->unsignedInteger('fallback_tax_id')->nullable()->after('direct_deposit_tax_id');
            $table->timestamp('disenrollment_requested_at')->nullable()->after('enrolled_at');

            $table->index(['offshore_id', 'disenrollment_requested_at'], 'dde_offshore_pending_idx');
            $table->index(['alliance_id', 'direct_deposit_tax_id'], 'dde_alliance_tax_idx');
        });

        $directDepositTaxId = (int) (DB::table('settings')->where('key', 'dd_tax_id')->value('value') ?? 0);
        $fallbackTaxId = (int) (DB::table('settings')->where('key', 'dd_fallback_tax_id')->value('value') ?? 0);

        DB::table('direct_deposit_enrollments')
            ->orderBy('id')
            ->chunkById(500, function ($enrollments) use ($directDepositTaxId, $fallbackTaxId): void {
                $nationAllianceIds = DB::table('nations')
                    ->whereIn('id', $enrollments->pluck('nation_id'))
                    ->pluck('alliance_id', 'id');
                $offshoreIds = DB::table('offshores')
                    ->whereIn('alliance_id', $nationAllianceIds->filter()->unique()->values())
                    ->orderByDesc('enabled')
                    ->orderBy('priority')
                    ->get(['id', 'alliance_id'])
                    ->unique('alliance_id')
                    ->pluck('id', 'alliance_id');

                foreach ($enrollments as $enrollment) {
                    $allianceId = $nationAllianceIds->get($enrollment->nation_id);

                    DB::table('direct_deposit_enrollments')
                        ->where('id', $enrollment->id)
                        ->update([
                            'offshore_id' => $allianceId ? $offshoreIds->get($allianceId) : null,
                            'alliance_id' => $allianceId,
                            'direct_deposit_tax_id' => $directDepositTaxId > 0 ? $directDepositTaxId : null,
                            'fallback_tax_id' => $fallbackTaxId > 0 ? $fallbackTaxId : null,
                        ]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('direct_deposit_enrollments', function (Blueprint $table) {
            $table->dropIndex('dde_offshore_pending_idx');
            $table->dropIndex('dde_alliance_tax_idx');
            $table->dropForeign(['offshore_id']);
            WorldReference::drop($table, 'alliance_id');
            $table->dropColumn([
                'offshore_id',
                'alliance_id',
                'direct_deposit_tax_id',
                'fallback_tax_id',
                'disenrollment_requested_at',
            ]);
        });

        Schema::table('offshores', function (Blueprint $table) {
            $table->dropColumn([
                'direct_deposit_enabled',
                'direct_deposit_tax_id',
                'direct_deposit_fallback_tax_id',
            ]);
        });
    }
};
