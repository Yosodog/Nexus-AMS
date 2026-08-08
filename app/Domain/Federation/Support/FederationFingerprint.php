<?php

namespace App\Domain\Federation\Support;

use InvalidArgumentException;

final class FederationFingerprint
{
    public static function signing(string $rawPublicKey): string
    {
        return self::format('Ed25519', 'signing', $rawPublicKey);
    }

    public static function encryption(string $rawPublicKey): string
    {
        return self::format('X25519', 'encryption', $rawPublicKey);
    }

    private static function format(string $algorithm, string $purpose, string $rawPublicKey): string
    {
        if (strlen($rawPublicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            && strlen($rawPublicKey) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
            throw new InvalidArgumentException('Unexpected federation public-key length.');
        }

        $digest = strtoupper(hash('sha256', $algorithm."\0".$purpose."\0".$rawPublicKey));

        return implode('-', str_split($digest, 4));
    }
}
