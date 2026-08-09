<?php

namespace App\Services\Discord\Relay;

use InvalidArgumentException;
use JsonException;

final class CanonicalJson
{
    public static function encode(mixed $value): string
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return '['.implode(',', array_map(self::encode(...), $value)).']';
            }

            $keys = array_keys($value);
            sort($keys, SORT_STRING);
            $members = [];
            foreach ($keys as $key) {
                if (! is_string($key)) {
                    throw new InvalidArgumentException('Canonical JSON object keys must be strings.');
                }
                $members[] = self::scalar($key).':'.self::encode($value[$key]);
            }

            return '{'.implode(',', $members).'}';
        }

        if (is_float($value)) {
            throw new InvalidArgumentException('Relay contracts do not accept floating-point values.');
        }
        if (! is_null($value) && ! is_bool($value) && ! is_int($value) && ! is_string($value)) {
            throw new InvalidArgumentException('Canonical JSON contains an unsupported value.');
        }

        return self::scalar($value);
    }

    private static function scalar(mixed $value): string
    {
        try {
            return json_encode(
                $value,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Canonical JSON contains invalid UTF-8.', previous: $exception);
        }
    }
}
