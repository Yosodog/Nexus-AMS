<?php

namespace App\Domain\Federation\Support;

use InvalidArgumentException;
use JsonException;

final class StrictJson
{
    private int $offset = 0;

    private int $length;

    private function __construct(private readonly string $json)
    {
        $this->length = strlen($json);
    }

    /**
     * @return array<string, mixed>
     */
    public static function decodeObject(string $json): array
    {
        $scanner = new self($json);
        $scanner->skipWhitespace();
        $scanner->scanObject();
        $scanner->skipWhitespace();

        if ($scanner->offset !== $scanner->length) {
            throw new InvalidArgumentException('JSON contains trailing data.');
        }

        try {
            $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Malformed JSON.', 0, $exception);
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new InvalidArgumentException('JSON root must be an object.');
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  list<string>  $allowed
     */
    public static function rejectUnknown(array $value, array $allowed): void
    {
        $unknown = array_values(array_diff(array_keys($value), $allowed));

        if ($unknown !== []) {
            throw new InvalidArgumentException('JSON contains unknown properties.');
        }
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  list<string>  $required
     */
    public static function requireProperties(array $value, array $required): void
    {
        foreach ($required as $property) {
            if (! array_key_exists($property, $value)) {
                throw new InvalidArgumentException('JSON is missing a required property.');
            }
        }
    }

    private function scanValue(): void
    {
        $this->skipWhitespace();
        $character = $this->current();

        match ($character) {
            '{' => $this->scanObject(),
            '[' => $this->scanArray(),
            '"' => $this->scanString(),
            default => $this->scanPrimitive(),
        };
    }

    private function scanObject(): void
    {
        $this->expect('{');
        $this->skipWhitespace();
        $keys = [];

        if ($this->consume('}')) {
            return;
        }

        while (true) {
            $this->skipWhitespace();
            $rawKey = $this->scanString();

            try {
                $key = json_decode($rawKey, true, 2, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new InvalidArgumentException('Malformed JSON object key.', 0, $exception);
            }

            if (! is_string($key) || array_key_exists($key, $keys)) {
                throw new InvalidArgumentException('JSON contains duplicate object properties.');
            }

            $keys[$key] = true;
            $this->skipWhitespace();
            $this->expect(':');
            $this->scanValue();
            $this->skipWhitespace();

            if ($this->consume('}')) {
                return;
            }

            $this->expect(',');
        }
    }

    private function scanArray(): void
    {
        $this->expect('[');
        $this->skipWhitespace();

        if ($this->consume(']')) {
            return;
        }

        while (true) {
            $this->scanValue();
            $this->skipWhitespace();

            if ($this->consume(']')) {
                return;
            }

            $this->expect(',');
        }
    }

    private function scanString(): string
    {
        $start = $this->offset;
        $this->expect('"');

        while ($this->offset < $this->length) {
            $character = $this->json[$this->offset++];

            if ($character === '"') {
                return substr($this->json, $start, $this->offset - $start);
            }

            if (ord($character) < 0x20) {
                throw new InvalidArgumentException('JSON string contains a control character.');
            }

            if ($character === '\\') {
                if ($this->offset >= $this->length) {
                    throw new InvalidArgumentException('JSON string has an invalid escape.');
                }

                $escaped = $this->json[$this->offset++];

                if (! str_contains('"\\/bfnrtu', $escaped)) {
                    throw new InvalidArgumentException('JSON string has an invalid escape.');
                }

                if ($escaped === 'u') {
                    $hex = substr($this->json, $this->offset, 4);

                    if (strlen($hex) !== 4 || preg_match('/^[0-9A-Fa-f]{4}$/D', $hex) !== 1) {
                        throw new InvalidArgumentException('JSON string has an invalid unicode escape.');
                    }

                    $this->offset += 4;
                }
            }
        }

        throw new InvalidArgumentException('JSON string is unterminated.');
    }

    private function scanPrimitive(): void
    {
        $start = $this->offset;

        while ($this->offset < $this->length
            && ! str_contains(" \t\r\n,]}", $this->json[$this->offset])) {
            $this->offset++;
        }

        if ($this->offset === $start) {
            throw new InvalidArgumentException('Malformed JSON value.');
        }
    }

    private function skipWhitespace(): void
    {
        while ($this->offset < $this->length && str_contains(" \t\r\n", $this->json[$this->offset])) {
            $this->offset++;
        }
    }

    private function current(): string
    {
        if ($this->offset >= $this->length) {
            throw new InvalidArgumentException('Unexpected end of JSON.');
        }

        return $this->json[$this->offset];
    }

    private function expect(string $character): void
    {
        if (! $this->consume($character)) {
            throw new InvalidArgumentException('Malformed JSON.');
        }
    }

    private function consume(string $character): bool
    {
        if ($this->offset < $this->length && $this->json[$this->offset] === $character) {
            $this->offset++;

            return true;
        }

        return false;
    }
}
