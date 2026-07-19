<?php

namespace App\Services;

use InvalidArgumentException;

class LotteryRandomizer
{
    public const CODE_LENGTH = 3;

    public const CODE_SPACE_SIZE = 46656;

    private const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    private const ALPHABET_SIZE = 36;

    private const FEISTEL_ROUNDS = 8;

    private const HALF_DOMAIN_SIZE = 216;

    private const SEED_BYTES = 32;

    public function permutationSeed(): string
    {
        return bin2hex(random_bytes(self::SEED_BYTES));
    }

    /**
     * @return list<string>
     */
    public function ticketCodesForRange(string $seed, int $startingSequence, int $quantity): array
    {
        if ($quantity < 0 || $startingSequence < 0 || $startingSequence + $quantity > self::CODE_SPACE_SIZE) {
            throw new InvalidArgumentException('The requested lottery ticket range is outside the available code space.');
        }

        $seedBytes = $this->seedBytes($seed);
        $codes = [];

        for ($sequence = $startingSequence; $sequence < $startingSequence + $quantity; $sequence++) {
            $codes[] = $this->ticketCodeForSequenceAndSeed($sequence, $seedBytes);
        }

        return $codes;
    }

    public function ticketCodeForSequence(string $seed, int $sequence): string
    {
        if ($sequence < 0 || $sequence >= self::CODE_SPACE_SIZE) {
            throw new InvalidArgumentException('The lottery ticket sequence is outside the available code space.');
        }

        return $this->ticketCodeForSequenceAndSeed($sequence, $this->seedBytes($seed));
    }

    public function ticketCode(): string
    {
        $code = '';

        for ($position = 0; $position < self::CODE_LENGTH; $position++) {
            $code .= self::ALPHABET[random_int(0, self::ALPHABET_SIZE - 1)];
        }

        return $code;
    }

    private function roundValue(string $seed, int $round, int $right): int
    {
        $digest = hash_hmac('sha256', pack('N2', $round, $right), $seed, true);
        $value = unpack('Nvalue', $digest);

        return $value['value'] % self::HALF_DOMAIN_SIZE;
    }

    private function seedBytes(string $seed): string
    {
        if (strlen($seed) !== self::SEED_BYTES * 2 || ! ctype_xdigit($seed)) {
            throw new InvalidArgumentException('The lottery permutation seed must be a 64-character hexadecimal string.');
        }

        $seedBytes = hex2bin($seed);

        if ($seedBytes === false) {
            throw new InvalidArgumentException('The lottery permutation seed is invalid.');
        }

        return $seedBytes;
    }

    private function ticketCodeForSequenceAndSeed(int $sequence, string $seed): string
    {
        $left = intdiv($sequence, self::HALF_DOMAIN_SIZE);
        $right = $sequence % self::HALF_DOMAIN_SIZE;

        for ($round = 0; $round < self::FEISTEL_ROUNDS; $round++) {
            $nextLeft = $right;
            $nextRight = ($left + $this->roundValue($seed, $round, $right)) % self::HALF_DOMAIN_SIZE;
            $left = $nextLeft;
            $right = $nextRight;
        }

        return $this->encode($left * self::HALF_DOMAIN_SIZE + $right);
    }

    private function encode(int $value): string
    {
        $code = '';

        for ($position = 0; $position < self::CODE_LENGTH; $position++) {
            $code = self::ALPHABET[$value % self::ALPHABET_SIZE].$code;
            $value = intdiv($value, self::ALPHABET_SIZE);
        }

        return $code;
    }
}
