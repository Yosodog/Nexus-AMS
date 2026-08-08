<?php

namespace App\Domain\Federation\Cryptography;

use App\Domain\Federation\Support\Base64Url;
use App\Domain\Federation\Support\FederationFingerprint;
use RuntimeException;

final class FederationCryptography
{
    /**
     * @return array{
     *     signing_public_key: string,
     *     signing_private_key: string,
     *     box_public_key: string,
     *     box_private_key: string,
     *     signing_fingerprint: string,
     *     box_fingerprint: string
     * }
     */
    public function generateKeyMaterial(): array
    {
        $this->assertAvailable();

        $signingPair = sodium_crypto_sign_keypair();
        $signingPublic = sodium_crypto_sign_publickey($signingPair);
        $signingPrivate = sodium_crypto_sign_secretkey($signingPair);
        $boxPair = sodium_crypto_box_keypair();
        $boxPublic = sodium_crypto_box_publickey($boxPair);
        $boxPrivate = sodium_crypto_box_secretkey($boxPair);

        return [
            'signing_public_key' => Base64Url::encode($signingPublic),
            'signing_private_key' => Base64Url::encode($signingPrivate),
            'box_public_key' => Base64Url::encode($boxPublic),
            'box_private_key' => Base64Url::encode($boxPrivate),
            'signing_fingerprint' => FederationFingerprint::signing($signingPublic),
            'box_fingerprint' => FederationFingerprint::encryption($boxPublic),
        ];
    }

    public function sign(string $message, string $encodedPrivateKey): string
    {
        $privateKey = Base64Url::decode($encodedPrivateKey);

        if (strlen($privateKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('Federation signing key is invalid.');
        }

        return Base64Url::encode(sodium_crypto_sign_detached($message, $privateKey));
    }

    public function verify(string $message, string $encodedSignature, string $encodedPublicKey): bool
    {
        try {
            $signature = Base64Url::decode($encodedSignature);
            $publicKey = Base64Url::decode($encodedPublicKey);
        } catch (\InvalidArgumentException) {
            return false;
        }

        if (strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($signature, $message, $publicKey);
    }

    public function seal(string $plaintext, string $encodedRecipientPublicKey): string
    {
        $publicKey = Base64Url::decode($encodedRecipientPublicKey);

        if (strlen($publicKey) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
            throw new RuntimeException('Federation recipient encryption key is invalid.');
        }

        return Base64Url::encode(sodium_crypto_box_seal($plaintext, $publicKey));
    }

    public function open(
        string $encodedCiphertext,
        string $encodedRecipientPublicKey,
        string $encodedRecipientPrivateKey,
    ): string|false {
        try {
            $ciphertext = Base64Url::decode($encodedCiphertext);
            $publicKey = Base64Url::decode($encodedRecipientPublicKey);
            $privateKey = Base64Url::decode($encodedRecipientPrivateKey);
        } catch (\InvalidArgumentException) {
            return false;
        }

        if (strlen($publicKey) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES
            || strlen($privateKey) !== SODIUM_CRYPTO_BOX_SECRETKEYBYTES) {
            return false;
        }

        $keypair = sodium_crypto_box_keypair_from_secretkey_and_publickey($privateKey, $publicKey);

        return sodium_crypto_box_seal_open($ciphertext, $keypair);
    }

    private function assertAvailable(): void
    {
        if (! extension_loaded('sodium')) {
            throw new RuntimeException('The sodium PHP extension is required for federation.');
        }
    }
}
