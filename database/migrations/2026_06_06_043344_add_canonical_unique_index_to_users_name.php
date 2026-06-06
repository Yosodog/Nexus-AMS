<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->deduplicateUsernames();

        $expression = $this->canonicalNameExpression();
        $driver = DB::getDriverName();

        Schema::table('users', function (Blueprint $table) use ($driver, $expression) {
            $column = $table->string('name_canonical');

            if ($driver === 'pgsql') {
                $column->storedAs($expression);

                return;
            }

            $column->virtualAs($expression);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unique('name_canonical', 'users_name_canonical_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_name_canonical_unique');
            $table->dropColumn('name_canonical');
        });
    }

    private function deduplicateUsernames(): void
    {
        $usedCanonicalNames = [];

        DB::table('users')
            ->select(['id', 'name'])
            ->orderBy('id')
            ->get()
            ->each(function (object $user) use (&$usedCanonicalNames): void {
                $canonicalName = $this->canonicalize((string) $user->name);

                if (! isset($usedCanonicalNames[$canonicalName])) {
                    $usedCanonicalNames[$canonicalName] = true;

                    return;
                }

                $replacement = $this->deduplicatedName((string) $user->name, (int) $user->id, $usedCanonicalNames);

                DB::table('users')
                    ->where('id', $user->id)
                    ->update(['name' => $replacement]);

                $usedCanonicalNames[$this->canonicalize($replacement)] = true;
            });
    }

    /**
     * @param  array<string, bool>  $usedCanonicalNames
     */
    private function deduplicatedName(string $name, int $userId, array $usedCanonicalNames): string
    {
        $attempt = 0;

        do {
            $suffix = $attempt === 0 ? "-duplicate-{$userId}" : "-duplicate-{$userId}-{$attempt}";
            $candidate = Str::limit($name, 255 - strlen($suffix), '').$suffix;
            $attempt++;
        } while (isset($usedCanonicalNames[$this->canonicalize($candidate)]));

        return $candidate;
    }

    private function canonicalize(string $name): string
    {
        return Str::lower($name);
    }

    private function canonicalNameExpression(): string
    {
        return match (DB::getDriverName()) {
            'mariadb', 'mysql' => 'LOWER(`name`)',
            'pgsql', 'sqlite' => 'LOWER("name")',
            default => 'LOWER(name)',
        };
    }
};
