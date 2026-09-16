<?php

use App\Support\Database\WorldReference;
use App\Support\Database\WorldSchema;
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
        $this->ensureOffshoreColumns();
        $this->ensureEnrollmentColumns();
        $this->ensureEnrollmentForeignKeys();
        $this->ensureEnrollmentIndexes();

        $directDepositTaxId = (int) (DB::table('settings')->where('key', 'dd_tax_id')->value('value') ?? 0);
        $fallbackTaxId = (int) (DB::table('settings')->where('key', 'dd_fallback_tax_id')->value('value') ?? 0);

        $this->backfillEnrollments($directDepositTaxId, $fallbackTaxId);
    }

    private function ensureOffshoreColumns(): void
    {
        if (! Schema::hasColumn('offshores', 'direct_deposit_enabled')) {
            Schema::table('offshores', function (Blueprint $table): void {
                $table->boolean('direct_deposit_enabled')->default(false)->after('enabled');
            });
        }

        if (! Schema::hasColumn('offshores', 'direct_deposit_tax_id')) {
            Schema::table('offshores', function (Blueprint $table): void {
                $table->unsignedInteger('direct_deposit_tax_id')->nullable()->after('direct_deposit_enabled');
            });
        }

        if (! Schema::hasColumn('offshores', 'direct_deposit_fallback_tax_id')) {
            Schema::table('offshores', function (Blueprint $table): void {
                $table->unsignedInteger('direct_deposit_fallback_tax_id')->nullable()->after('direct_deposit_tax_id');
            });
        }
    }

    private function ensureEnrollmentColumns(): void
    {
        if (! Schema::hasColumn('direct_deposit_enrollments', 'offshore_id')) {
            Schema::table('direct_deposit_enrollments', function (Blueprint $table): void {
                $table->foreignId('offshore_id')
                    ->nullable()
                    ->after('nation_id')
                    ->constrained('offshores')
                    ->restrictOnDelete();
            });
        }

        if (! Schema::hasColumn('direct_deposit_enrollments', 'alliance_id')) {
            Schema::table('direct_deposit_enrollments', function (Blueprint $table): void {
                WorldReference::alliance($table, 'alliance_id')
                    ->nullable()
                    ->after('offshore_id');
            });
        }

        if (! Schema::hasColumn('direct_deposit_enrollments', 'direct_deposit_tax_id')) {
            Schema::table('direct_deposit_enrollments', function (Blueprint $table): void {
                $table->unsignedInteger('direct_deposit_tax_id')->nullable()->after('account_id');
            });
        }

        if (! Schema::hasColumn('direct_deposit_enrollments', 'fallback_tax_id')) {
            Schema::table('direct_deposit_enrollments', function (Blueprint $table): void {
                $table->unsignedInteger('fallback_tax_id')->nullable()->after('direct_deposit_tax_id');
            });
        }

        if (! Schema::hasColumn('direct_deposit_enrollments', 'disenrollment_requested_at')) {
            Schema::table('direct_deposit_enrollments', function (Blueprint $table): void {
                $table->timestamp('disenrollment_requested_at')->nullable()->after('enrolled_at');
            });
        }
    }

    private function ensureEnrollmentForeignKeys(): void
    {
        if (! $this->hasForeignKey('direct_deposit_enrollments', 'offshore_id', 'offshores')) {
            Schema::table('direct_deposit_enrollments', function (Blueprint $table): void {
                $table->foreign('offshore_id')
                    ->references('id')
                    ->on('offshores')
                    ->restrictOnDelete();
            });
        }

        if (WorldSchema::usesPhysicalTables()
            && ! $this->hasForeignKey('direct_deposit_enrollments', 'alliance_id', 'alliances')) {
            Schema::table('direct_deposit_enrollments', function (Blueprint $table): void {
                $table->foreign('alliance_id')
                    ->references('id')
                    ->on('alliances');
            });
        }
    }

    private function ensureEnrollmentIndexes(): void
    {
        if (! Schema::hasIndex('direct_deposit_enrollments', 'dde_offshore_pending_idx')) {
            Schema::table('direct_deposit_enrollments', function (Blueprint $table): void {
                $table->index(['offshore_id', 'disenrollment_requested_at'], 'dde_offshore_pending_idx');
            });
        }

        if (! Schema::hasIndex('direct_deposit_enrollments', 'dde_alliance_tax_idx')) {
            Schema::table('direct_deposit_enrollments', function (Blueprint $table): void {
                $table->index(['alliance_id', 'direct_deposit_tax_id'], 'dde_alliance_tax_idx');
            });
        }
    }

    private function hasForeignKey(string $tableName, string $column, string $referencedTable): bool
    {
        foreach (Schema::getForeignKeys($tableName) as $foreignKey) {
            if (($foreignKey['columns'] ?? []) === [$column]
                && ($foreignKey['foreign_table'] ?? null) === $referencedTable) {
                return true;
            }
        }

        return false;
    }

    private function backfillEnrollments(int $directDepositTaxId, int $fallbackTaxId): void
    {
        DB::table('direct_deposit_enrollments')
            ->orderBy('id')
            ->chunkById(500, function ($enrollments) use ($directDepositTaxId, $fallbackTaxId): void {
                $nationAllianceIds = DB::table('nations')
                    ->whereIn('id', $enrollments->pluck('nation_id'))
                    ->pluck('alliance_id', 'id');
                $offshoreIds = DB::table('offshores')
                    ->whereIn(
                        'alliance_id',
                        $nationAllianceIds
                            ->filter(fn ($allianceId): bool => (int) $allianceId > 0)
                            ->map(fn ($allianceId): int => (int) $allianceId)
                            ->unique()
                            ->values(),
                    )
                    ->orderByDesc('enabled')
                    ->orderBy('priority')
                    ->get(['id', 'alliance_id'])
                    ->unique('alliance_id')
                    ->pluck('id', 'alliance_id');

                foreach ($enrollments as $enrollment) {
                    $allianceId = $nationAllianceIds->get($enrollment->nation_id);
                    $allianceId = $allianceId !== null && (int) $allianceId > 0
                        ? (int) $allianceId
                        : null;

                    DB::table('direct_deposit_enrollments')
                        ->where('id', $enrollment->id)
                        ->update([
                            'offshore_id' => $allianceId === null ? null : $offshoreIds->get($allianceId),
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
