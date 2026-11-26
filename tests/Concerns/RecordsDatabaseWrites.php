<?php

namespace Tests\Concerns;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

trait RecordsDatabaseWrites
{
    /**
     * Run a callback and report the INSERT/UPDATE/DELETE statements it issued against a table
     * and the number of rows the connection changed (SQLite total_changes()).
     *
     * @return array{rows: int, statements: list<QueryExecuted>}
     */
    protected function recordWrites(string $table, callable $callback): array
    {
        $statements = [];
        $recording = true;

        DB::listen(function (QueryExecuted $query) use ($table, &$statements, &$recording): void {
            if ($recording && preg_match('/^\s*(insert|update|delete)\b.*\b'.preg_quote($table, '/').'\b/is', $query->sql) === 1) {
                $statements[] = $query;
            }
        });

        $before = $this->totalChanges();
        $callback();
        $rows = $this->totalChanges() - $before;
        $recording = false;

        return ['rows' => $rows, 'statements' => $statements];
    }

    private function totalChanges(): int
    {
        return (int) DB::selectOne('select total_changes() as changes')->changes;
    }
}
