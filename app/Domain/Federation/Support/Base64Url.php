<?php

namespace App\Domain\Federation\Support;

use InvalidArgumentException;

final class Base64Url
{
    public static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    public static function decode(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1) {
            throw new InvalidArgumentException('Invalid base64url value.');
        }

        $padding = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(strtr($value.str_repeat('=', $padding), '-_', '+/'), true);

        if (! is_string($decoded) || ! hash_equals(self::encode($decoded), $value)) {
            throw new InvalidArgumentException('Invalid base64url value.');
        }

        return $decoded;
    }
}
