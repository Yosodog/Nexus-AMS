<?php

namespace App\Services\Discord\Relay;

/** A bounded JSON decoder that rejects duplicate object member names. */
final class StrictJson
{
    private int $offset = 0;

    private int $depth = 0;

    private function __construct(
        private readonly string $json,
        private readonly int $maxDepth,
    ) {}

    public static function decode(string $json, int $maxDepth = 32): mixed
    {
        $parser = new self($json, $maxDepth);
        $value = $parser->value();
        $parser->whitespace();

        if ($parser->offset !== strlen($json)) {
            throw new StrictJsonException('JSON contains trailing data.');
        }

        return $value;
    }

    private function value(): mixed
    {
        $this->whitespace();
        $character = $this->json[$this->offset] ?? null;

        return match ($character) {
            '{' => $this->object(),
            '[' => $this->array(),
            '"' => $this->string(),
            't' => $this->literal('true', true),
            'f' => $this->literal('false', false),
            'n' => $this->literal('null', null),
            default => $this->number(),
        };
    }

    /** @return array<string, mixed> */
    private function object(): array
    {
        $this->enter();
        $this->offset++;
        $this->whitespace();
        $result = [];

        if (($this->json[$this->offset] ?? null) === '}') {
            $this->offset++;
            $this->leave();

            return $result;
        }

        while (true) {
            $this->whitespace();
            if (($this->json[$this->offset] ?? null) !== '"') {
                throw new StrictJsonException('JSON object members require string names.');
            }
            $key = $this->string();
            if (array_key_exists($key, $result)) {
                throw new StrictJsonException('JSON contains a duplicate object member.');
            }

            $this->whitespace();
            if (($this->json[$this->offset] ?? null) !== ':') {
                throw new StrictJsonException('JSON object member is missing a colon.');
            }
            $this->offset++;
            $result[$key] = $this->value();
            $this->whitespace();
            $delimiter = $this->json[$this->offset] ?? null;
            if ($delimiter === '}') {
                $this->offset++;
                $this->leave();

                return $result;
            }
            if ($delimiter !== ',') {
                throw new StrictJsonException('JSON object member is missing a delimiter.');
            }
            $this->offset++;
        }
    }

    /** @return list<mixed> */
    private function array(): array
    {
        $this->enter();
        $this->offset++;
        $this->whitespace();
        $result = [];

        if (($this->json[$this->offset] ?? null) === ']') {
            $this->offset++;
            $this->leave();

            return $result;
        }

        while (true) {
            $result[] = $this->value();
            $this->whitespace();
            $delimiter = $this->json[$this->offset] ?? null;
            if ($delimiter === ']') {
                $this->offset++;
                $this->leave();

                return $result;
            }
            if ($delimiter !== ',') {
                throw new StrictJsonException('JSON array item is missing a delimiter.');
            }
            $this->offset++;
        }
    }

    private function string(): string
    {
        $start = $this->offset;
        $this->offset++;
        $escaped = false;

        while ($this->offset < strlen($this->json)) {
            $character = $this->json[$this->offset++];
            if ($escaped) {
                $escaped = false;

                continue;
            }
            if ($character === '\\') {
                $escaped = true;

                continue;
            }
            if ($character === '"') {
                $encoded = substr($this->json, $start, $this->offset - $start);
                try {
                    $decoded = json_decode($encoded, true, 2, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    throw new StrictJsonException('JSON contains an invalid string.');
                }

                if (! is_string($decoded)) {
                    throw new StrictJsonException('JSON contains an invalid string.');
                }

                return $decoded;
            }
        }

        throw new StrictJsonException('JSON contains an unterminated string.');
    }

    private function number(): int|float
    {
        if (preg_match(
            '/-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/A',
            substr($this->json, $this->offset),
            $matches,
        ) !== 1) {
            throw new StrictJsonException('JSON contains an invalid value.');
        }

        $this->offset += strlen($matches[0]);
        try {
            $decoded = json_decode($matches[0], true, 2, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new StrictJsonException('JSON contains an invalid number.');
        }

        if (! is_int($decoded) && ! is_float($decoded)) {
            throw new StrictJsonException('JSON contains an invalid number.');
        }

        return $decoded;
    }

    private function literal(string $literal, mixed $value): mixed
    {
        if (substr($this->json, $this->offset, strlen($literal)) !== $literal) {
            throw new StrictJsonException('JSON contains an invalid literal.');
        }
        $this->offset += strlen($literal);

        return $value;
    }

    private function whitespace(): void
    {
        while (isset($this->json[$this->offset])
            && in_array($this->json[$this->offset], [' ', "\t", "\r", "\n"], true)) {
            $this->offset++;
        }
    }

    private function enter(): void
    {
        $this->depth++;
        if ($this->depth > $this->maxDepth) {
            throw new StrictJsonException('JSON nesting exceeds the accepted depth.');
        }
    }

    private function leave(): void
    {
        $this->depth--;
    }
}
