<?php

namespace Tests\Unit\Federation;

use App\Domain\Federation\Cryptography\FederationCryptography;
use App\Domain\Federation\Protocol\FederationEnvelopeService;
use App\Domain\Federation\Support\Base64Url;
use App\Domain\Federation\Support\FederationFingerprint;
use InvalidArgumentException;
use Tests\TestCase;

class CryptographyTest extends TestCase
{
    public function test_ed25519_matches_the_rfc_8032_empty_message_vector(): void
    {
        $seed = hex2bin('9d61b19deffd5a60ba844af492ec2cc44449c5697b326919703bac031cae7f60');
        $pair = sodium_crypto_sign_seed_keypair($seed);
        $private = sodium_crypto_sign_secretkey($pair);
        $public = sodium_crypto_sign_publickey($pair);
        $expectedPublic = 'd75a980182b10ab7d54bfed3c964073a0ee172f3daa62325af021a68f707511a';
        $expectedSignature = 'e5564300c360ac729086e2cc806e828a84877f1eb8e5d974d873e06522490155'
            .'5fb8821590a33bacc61e39701cf9b46bd25bf5f0595bbe24655141438e7a100b';
        $crypto = new FederationCryptography;

        $this->assertSame($expectedPublic, bin2hex($public));
        $signature = $crypto->sign('', Base64Url::encode($private));
        $this->assertSame($expectedSignature, bin2hex(Base64Url::decode($signature)));
        $this->assertTrue($crypto->verify('', $signature, Base64Url::encode($public)));
    }

    public function test_sealed_box_round_trip_rejects_wrong_recipient_and_tampering(): void
    {
        $crypto = new FederationCryptography;
        $recipient = $crypto->generateKeyMaterial();
        $other = $crypto->generateKeyMaterial();
        $ciphertext = $crypto->seal('classified payload', $recipient['box_public_key']);

        $this->assertSame('classified payload', $crypto->open(
            $ciphertext,
            $recipient['box_public_key'],
            $recipient['box_private_key'],
        ));
        $this->assertFalse($crypto->open(
            $ciphertext,
            $other['box_public_key'],
            $other['box_private_key'],
        ));

        $raw = Base64Url::decode($ciphertext);
        $raw[10] = chr(ord($raw[10]) ^ 1);
        $this->assertFalse($crypto->open(
            Base64Url::encode($raw),
            $recipient['box_public_key'],
            $recipient['box_private_key'],
        ));
    }

    public function test_base64url_and_signature_framing_are_exact_and_unambiguous(): void
    {
        $this->assertSame('_-4', Base64Url::encode("\xff\xee"));
        $this->assertSame("\xff\xee", Base64Url::decode('_-4'));
        $this->assertSame(
            "nexus-federation-envelope-v1\nversion:3:1.0\nprotected:3:a:b\nciphertext:3:c:d",
            FederationEnvelopeService::signatureInput('1.0', 'a:b', 'c:d')
        );

        $this->expectException(InvalidArgumentException::class);
        Base64Url::decode('abc=');
    }

    public function test_fingerprint_is_stable_and_purpose_separated(): void
    {
        $key = str_repeat("\x01", 32);

        $this->assertSame(FederationFingerprint::signing($key), FederationFingerprint::signing($key));
        $this->assertNotSame(FederationFingerprint::signing($key), FederationFingerprint::encryption($key));
        $this->assertMatchesRegularExpression('/^(?:[A-F0-9]{4}-){15}[A-F0-9]{4}$/', FederationFingerprint::signing($key));
    }
}
