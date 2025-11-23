<?php

namespace App\Nel;

use App\Nel\Exception\NelSyntaxException;

final class NelTokenizer
{
    /**
     * @return Token[]
     */
    public function tokenize(string $input): array
    {
        $length = strlen($input);
        $position = 0;
        $tokens = [];

        while ($position < $length) {
            $char = $input[$position];

            if (ctype_space($char)) {
                $position++;

                continue;
            }

            if ($this->isIdentifierStart($char)) {
                [$token, $position] = $this->readIdentifier($input, $position);
                $tokens[] = $token;

                continue;
            }

            if (ctype_digit($char)) {
                [$token, $position] = $this->readNumber($input, $position);
                $tokens[] = $token;

                continue;
            }

            if ($char === '"' || $char === "'") {
                [$token, $position] = $this->readString($input, $position, $char);
                $tokens[] = $token;

                continue;
            }

            [$token, $position] = $this->readSymbol($input, $position);
            $tokens[] = $token;
        }

        $tokens[] = new Token(TokenType::END, null, $position);

        return $tokens;
    }

    private function isIdentifierStart(string $char): bool
    {
        return ctype_alpha($char) || $char === '_';
    }

    private function isIdentifierPart(string $char): bool
    {
        return ctype_alnum($char) || $char === '_';
    }

    /**
     * @return array{Token,int}
     */
    private function readIdentifier(string $input, int $position): array
    {
        $start = $position;
        $length = strlen($input);

        while ($position < $length && $this->isIdentifierPart($input[$position])) {
            $position++;
        }

        $value = substr($input, $start, $position - $start);

        if ($value === 'true' || $value === 'false') {
            return [new Token(TokenType::BOOLEAN, $value === 'true', $start), $position];
        }

        if ($value === 'null') {
            return [new Token(TokenType::NULL, null, $start), $position];
        }

        return [new Token(TokenType::IDENTIFIER, $value, $start), $position];
    }

    /**
     * @return array{Token,int}
     */
    private function readNumber(string $input, int $position): array
    {
        $start = $position;
        $length = strlen($input);
        $hasDot = false;

        while ($position < $length) {
            $char = $input[$position];
            if ($char === '.') {
                if ($hasDot) {
                    break;
                }
                $hasDot = true;
                $position++;

                continue;
            }

            if (! ctype_digit($char)) {
                break;
            }

            $position++;
        }

        $raw = substr($input, $start, $position - $start);
        $value = $hasDot ? (float) $raw : (int) $raw;

        return [new Token(TokenType::NUMBER, $value, $start), $position];
    }

    /**
     * @return array{Token,int}
     */
    private function readString(string $input, int $position, string $delimiter): array
    {
        $start = $position;
        $position++; // Skip opening quote
        $length = strlen($input);
        $value = '';

        while ($position < $length) {
            $char = $input[$position];

            if ($char === '\\') {
                $position++;
                if ($position >= $length) {
                    throw new NelSyntaxException('Unterminated escape sequence at position '.$position);
                }

                $escaped = $input[$position];
                $value .= match ($escaped) {
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    '"' => '"',
                    '\'' => '\'',
                    '\\' => '\\',
                    default => $escaped,
                };
                $position++;

                continue;
            }

            if ($char === $delimiter) {
                $position++;

                return [new Token(TokenType::STRING, $value, $start), $position];
            }

            $value .= $char;
            $position++;
        }

        throw new NelSyntaxException('Unterminated string starting at position '.$start);
    }

    /**
     * @return array{Token,int}
     */
    private function readSymbol(string $input, int $position): array
    {
        $char = $input[$position];
        $next = $input[$position + 1] ?? '';
        $start = $position;

        $twoChar = $char.$next;
        $map = [
            '&&' => TokenType::AND,
            '||' => TokenType::OR,
            '==' => TokenType::EQUAL_EQUAL,
            '!=' => TokenType::BANG_EQUAL,
            '<=' => TokenType::LESS_EQUAL,
            '>=' => TokenType::GREATER_EQUAL,
        ];

        if (isset($map[$twoChar])) {
            $position += 2;

            return [new Token($map[$twoChar], $twoChar, $start), $position];
        }

        $singleMap = [
            '.' => TokenType::DOT,
            '(' => TokenType::LPAREN,
            ')' => TokenType::RPAREN,
            ',' => TokenType::COMMA,
            '+' => TokenType::PLUS,
            '-' => TokenType::MINUS,
            '*' => TokenType::STAR,
            '/' => TokenType::SLASH,
            '%' => TokenType::PERCENT,
            '!' => TokenType::BANG,
            '<' => TokenType::LESS,
            '>' => TokenType::GREATER,
        ];

        if (isset($singleMap[$char])) {
            $position++;

            return [new Token($singleMap[$char], $char, $start), $position];
        }

        throw new NelSyntaxException('Unexpected character "'.$char.'" at position '.$position);
    }
}
