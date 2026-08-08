<?php

namespace App\Domain\Federation\Support;

use JsonException;

final class CanonicalJson
{
    /**
     * @param  array<string, mixed>|list<mixed>  $value
     *
     * @throws JsonException
     */
    public static function encode(array $value): string
    {
        return json_encode(
            self::sort($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        );
    }

    /**
     * @return array<string, mixed>|list<mixed>
     */
    private static function sort(array $value): array
    {
        if (array_is_list($value)) {
            return array_map(
                fn (mixed $item): mixed => is_array($item) ? self::sort($item) : $item,
                $value
            );
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sort($item);
            }
        }

        return $value;
    }
}
