<?php

use App\Enums\ApplicationStatus;
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
        $this->addPendingKeyColumns();

        $this->deduplicateWarAidRequests();
        $this->deduplicateApplications();
        $this->deduplicateLoans();
        $this->deduplicateDepositRequests();

        DB::table('war_aid_requests')
            ->where('status', 'pending')
            ->update(['pending_key' => 1]);

        DB::table('applications')
            ->where('status', ApplicationStatus::Pending->value)
            ->update(['pending_key' => 1]);

        DB::table('loans')
            ->where('status', 'pending')
            ->update(['pending_key' => 1]);

        DB::table('deposit_requests')
            ->where('status', 'pending')
            ->update(['pending_key' => 1]);

        Schema::table('war_aid_requests', function (Blueprint $table) {
            $table->unique(['nation_id', 'pending_key'], 'war_aid_requests_pending_unique');
        });

        Schema::table('applications', function (Blueprint $table) {
            $table->unique(['nation_id', 'pending_key'], 'applications_nation_pending_unique');
            $table->unique(['discord_user_id', 'pending_key'], 'applications_discord_pending_unique');
        });

        Schema::table('loans', function (Blueprint $table) {
            $table->unique(['nation_id', 'pending_key'], 'loans_pending_unique');
        });

        Schema::table('deposit_requests', function (Blueprint $table) {
            $table->unique(['account_id', 'pending_key'], 'deposit_requests_pending_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('war_aid_requests', function (Blueprint $table) {
            $table->dropUnique('war_aid_requests_pending_unique');
            $table->dropColumn('pending_key');
        });

        Schema::table('applications', function (Blueprint $table) {
            $table->dropUnique('applications_nation_pending_unique');
            $table->dropUnique('applications_discord_pending_unique');
            $table->dropColumn('pending_key');
        });

        Schema::table('loans', function (Blueprint $table) {
            $table->dropUnique('loans_pending_unique');
            $table->dropColumn('pending_key');
        });

        Schema::table('deposit_requests', function (Blueprint $table) {
            $table->dropUnique('deposit_requests_pending_unique');
            $table->dropColumn('pending_key');
        });
    }

    private function addPendingKeyColumns(): void
    {
        if (! Schema::hasColumn('war_aid_requests', 'pending_key')) {
            Schema::table('war_aid_requests', function (Blueprint $table) {
                $table->unsignedTinyInteger('pending_key')->nullable()->after('status');
            });
        }

        if (! Schema::hasColumn('applications', 'pending_key')) {
            Schema::table('applications', function (Blueprint $table) {
                $table->unsignedTinyInteger('pending_key')->nullable()->after('status');
            });
        }

        if (! Schema::hasColumn('loans', 'pending_key')) {
            Schema::table('loans', function (Blueprint $table) {
                $table->unsignedTinyInteger('pending_key')->nullable()->after('status');
            });
        }

        if (! Schema::hasColumn('deposit_requests', 'pending_key')) {
            Schema::table('deposit_requests', function (Blueprint $table) {
                $table->unsignedTinyInteger('pending_key')->nullable()->after('status');
            });
        }
    }

    private function deduplicateWarAidRequests(): void
    {
        $duplicates = DB::table('war_aid_requests')
            ->select('nation_id', DB::raw('COUNT(*) as total'))
            ->where('status', 'pending')
            ->groupBy('nation_id')
            ->having('total', '>', 1)
            ->get();

        foreach ($duplicates as $duplicate) {
            $pendingIds = DB::table('war_aid_requests')
                ->where('nation_id', $duplicate->nation_id)
                ->where('status', 'pending')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->pluck('id');

            $pendingIds->shift();

            if ($pendingIds->isNotEmpty()) {
                DB::table('war_aid_requests')
                    ->whereIn('id', $pendingIds->all())
                    ->update([
                        'status' => 'denied',
                        'denied_at' => now(),
                        'pending_key' => null,
                    ]);
            }
        }
    }

    private function deduplicateApplications(): void
    {
        $duplicateIds = collect();

        $nationDuplicates = DB::table('applications')
            ->select('nation_id', DB::raw('COUNT(*) as total'))
            ->where('status', ApplicationStatus::Pending->value)
            ->groupBy('nation_id')
            ->having('total', '>', 1)
            ->get();

        foreach ($nationDuplicates as $duplicate) {
            $pendingIds = DB::table('applications')
                ->where('nation_id', $duplicate->nation_id)
                ->where('status', ApplicationStatus::Pending->value)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->pluck('id');

            $pendingIds->shift();

            $duplicateIds = $duplicateIds->merge($pendingIds);
        }

        $discordDuplicates = DB::table('applications')
            ->select('discord_user_id', DB::raw('COUNT(*) as total'))
            ->where('status', ApplicationStatus::Pending->value)
            ->groupBy('discord_user_id')
            ->having('total', '>', 1)
            ->get();

        foreach ($discordDuplicates as $duplicate) {
            $pendingIds = DB::table('applications')
                ->where('discord_user_id', $duplicate->discord_user_id)
                ->where('status', ApplicationStatus::Pending->value)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->pluck('id');

            $pendingIds->shift();

            $duplicateIds = $duplicateIds->merge($pendingIds);
        }

        $duplicateIds = $duplicateIds->unique()->values();

        if ($duplicateIds->isNotEmpty()) {
            DB::table('applications')
                ->whereIn('id', $duplicateIds->all())
                ->update([
                    'status' => ApplicationStatus::Cancelled->value,
                    'cancelled_at' => now(),
                    'pending_key' => null,
                ]);
        }
    }

    private function deduplicateLoans(): void
    {
        $duplicates = DB::table('loans')
            ->select('nation_id', DB::raw('COUNT(*) as total'))
            ->where('status', 'pending')
            ->groupBy('nation_id')
            ->having('total', '>', 1)
            ->get();

        foreach ($duplicates as $duplicate) {
            $pendingIds = DB::table('loans')
                ->where('nation_id', $duplicate->nation_id)
                ->where('status', 'pending')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->pluck('id');

            $pendingIds->shift();

            if ($pendingIds->isNotEmpty()) {
                DB::table('loans')
                    ->whereIn('id', $pendingIds->all())
                    ->update([
                        'status' => 'denied',
                        'pending_key' => null,
                    ]);
            }
        }
    }

    private function deduplicateDepositRequests(): void
    {
        $duplicates = DB::table('deposit_requests')
            ->select('account_id', DB::raw('COUNT(*) as total'))
            ->where('status', 'pending')
            ->groupBy('account_id')
            ->having('total', '>', 1)
            ->get();

        foreach ($duplicates as $duplicate) {
            $pendingIds = DB::table('deposit_requests')
                ->where('account_id', $duplicate->account_id)
                ->where('status', 'pending')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->pluck('id');

            $pendingIds->shift();

            if ($pendingIds->isNotEmpty()) {
                DB::table('deposit_requests')
                    ->whereIn('id', $pendingIds->all())
                    ->update([
                        'status' => 'expired',
                        'pending_key' => null,
                    ]);
            }
        }
    }
};
