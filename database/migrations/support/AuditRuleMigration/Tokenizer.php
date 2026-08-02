<?php

namespace Database\Migrations\Support\AuditRuleMigration;

final class Tokenizer
{
    /**
     * @return list<Token>
     */
    public function tokenize(string $input): array
    {
        $length = strlen($input);
        $position = 0;
        $tokens = [];

        while ($position < $length) {
            $character = $input[$position];

            if (ctype_space($character)) {
                $position++;

                continue;
            }

            if ($this->isIdentifierStart($character)) {
                [$token, $position] = $this->readIdentifier($input, $position);
                $tokens[] = $token;

                continue;
            }

            if (ctype_digit($character)) {
                [$token, $position] = $this->readNumber($input, $position);
                $tokens[] = $token;

                continue;
            }

            if ($character === '"' || $character === "'") {
                [$token, $position] = $this->readString($input, $position, $character);
                $tokens[] = $token;

                continue;
            }

            [$token, $position] = $this->readSymbol($input, $position);
            $tokens[] = $token;
        }

        $tokens[] = new Token(TokenType::End, null, $position);

        return $tokens;
    }

    private function isIdentifierStart(string $character): bool
    {
        return ctype_alpha($character) || $character === '_';
    }

    private function isIdentifierPart(string $character): bool
    {
        return ctype_alnum($character) || $character === '_';
    }

    /**
     * @return array{Token, int}
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
            return [new Token(TokenType::Boolean, $value === 'true', $start), $position];
        }

        if ($value === 'null') {
            return [new Token(TokenType::Null, null, $start), $position];
        }

        return [new Token(TokenType::Identifier, $value, $start), $position];
    }

    /**
     * @return array{Token, int}
     */
    private function readNumber(string $input, int $position): array
    {
        $start = $position;
        $length = strlen($input);
        $hasDecimal = false;

        while ($position < $length) {
            $character = $input[$position];

            if ($character === '.') {
                if ($hasDecimal) {
                    break;
                }

                $hasDecimal = true;
                $position++;

                continue;
            }

            if (! ctype_digit($character)) {
                break;
            }

            $position++;
        }

        $rawValue = substr($input, $start, $position - $start);

        if ($rawValue === '' || $rawValue === '.') {
            throw new SyntaxException('Invalid number at position '.$start.'.');
        }

        return [
            new Token(TokenType::Number, $hasDecimal ? (float) $rawValue : (int) $rawValue, $start),
            $position,
        ];
    }

    /**
     * @return array{Token, int}
     */
    private function readString(string $input, int $position, string $delimiter): array
    {
        $start = $position;
        $length = strlen($input);
        $value = '';
        $position++;

        while ($position < $length) {
            $character = $input[$position];

            if ($character === '\\') {
                $position++;

                if ($position >= $length) {
                    throw new SyntaxException('Unterminated escape sequence at position '.$position.'.');
                }

                $value .= match ($input[$position]) {
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    '"' => '"',
                    "'" => "'",
                    '\\' => '\\',
                    default => $input[$position],
                };
                $position++;

                continue;
            }

            if ($character === $delimiter) {
                return [new Token(TokenType::String, $value, $start), $position + 1];
            }

            $value .= $character;
            $position++;
        }

        throw new SyntaxException('Unterminated string starting at position '.$start.'.');
    }

    /**
     * @return array{Token, int}
     */
    private function readSymbol(string $input, int $position): array
    {
        $character = $input[$position];
        $nextCharacter = $input[$position + 1] ?? '';
        $start = $position;
        $twoCharacters = $character.$nextCharacter;
        $twoCharacterTypes = [
            '&&' => TokenType::And,
            '||' => TokenType::Or,
            '==' => TokenType::Equal,
            '!=' => TokenType::NotEqual,
            '<=' => TokenType::LessOrEqual,
            '>=' => TokenType::GreaterOrEqual,
        ];

        if (isset($twoCharacterTypes[$twoCharacters])) {
            return [new Token($twoCharacterTypes[$twoCharacters], $twoCharacters, $start), $position + 2];
        }

        $singleCharacterTypes = [
            '.' => TokenType::Dot,
            '(' => TokenType::LeftParenthesis,
            ')' => TokenType::RightParenthesis,
            ',' => TokenType::Comma,
            '+' => TokenType::Plus,
            '-' => TokenType::Minus,
            '*' => TokenType::Star,
            '/' => TokenType::Slash,
            '%' => TokenType::Percent,
            '!' => TokenType::Bang,
            '<' => TokenType::Less,
            '>' => TokenType::Greater,
        ];

        if (isset($singleCharacterTypes[$character])) {
            return [new Token($singleCharacterTypes[$character], $character, $start), $position + 1];
        }

        throw new SyntaxException('Unexpected character "'.$character.'" at position '.$position.'.');
    }
}
