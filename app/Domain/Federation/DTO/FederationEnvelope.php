<?php

namespace App\Domain\Federation\DTO;

use App\Domain\Federation\Support\StrictJson;
use InvalidArgumentException;
use JsonException;

final readonly class FederationEnvelope
{
    public function __construct(
        public string $version,
        public string $protected,
        public string $ciphertext,
        public string $signature,
    ) {}

    public static function fromJson(string $json): self
    {
        $data = StrictJson::decodeObject($json);
        StrictJson::rejectUnknown($data, ['version', 'protected', 'ciphertext', 'signature']);
        StrictJson::requireProperties($data, ['version', 'protected', 'ciphertext', 'signature']);

        foreach ($data as $value) {
            if (! is_string($value) || $value === '') {
                throw new InvalidArgumentException('Envelope fields must be non-empty strings.');
            }
        }

        return new self($data['version'], $data['protected'], $data['ciphertext'], $data['signature']);
    }

    /** @throws JsonException */
    public function toJson(): string
    {
        return json_encode([
            'version' => $this->version,
            'protected' => $this->protected,
            'ciphertext' => $this->ciphertext,
            'signature' => $this->signature,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
