<?php

namespace App\Support;

class CsvExport
{
    /**
     * @param  resource  $handle
     * @param  array<int, mixed>  $row
     */
    public static function writeRow($handle, array $row): void
    {
        fputcsv($handle, self::sanitizeRow($row));
    }

    /**
     * @param  array<int, mixed>  $row
     * @return array<int, mixed>
     */
    public static function sanitizeRow(array $row): array
    {
        return array_map(self::sanitizeCell(...), $row);
    }

    public static function sanitizeCell(mixed $cell): mixed
    {
        if (! is_string($cell) && ! $cell instanceof \Stringable) {
            return $cell;
        }

        $value = (string) $cell;

        if ($value === '' || str_starts_with($value, "'")) {
            return $value;
        }

        if (preg_match('/^[\t\r\n=+\-@]/', $value) === 1 || preg_match('/^\s+[=+\-@]/', $value) === 1) {
            return "'{$value}";
        }

        return $value;
    }
}
