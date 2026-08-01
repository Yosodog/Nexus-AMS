<?php

namespace App\Http\Requests\Admin\Customization;

use Illuminate\Validation\Validator;

/**
 * Validate preview submissions from the customization editor.
 */
class CustomizationPreviewRequest extends CustomizationContentRequest
{
    private const MAX_CONTENT_CHARACTERS = 100_000;

    private const MAX_PAYLOAD_BYTES = 131_072;

    private const MAX_COLLECTION_ITEMS = 100;

    private const MAX_NESTING_DEPTH = 6;

    private const MAX_NESTED_STRING_CHARACTERS = 2_000;

    private const MAX_NESTED_ITEMS = 500;

    /**
     * @return array<string, array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'content' => ['required', 'string', 'max:'.self::MAX_CONTENT_CHARACTERS],
            'metadata' => ['sometimes', 'array', 'max:25'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $payload = json_encode(
                $this->only(['content', 'metadata']),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );

            if (! is_string($payload) || strlen($payload) > self::MAX_PAYLOAD_BYTES) {
                $validator->errors()->add(
                    'content',
                    'The preview payload may not exceed '.self::MAX_PAYLOAD_BYTES.' encoded bytes.'
                );
            }

            $itemCount = 0;
            $violation = $this->nestedPayloadViolation(
                $this->input('metadata', []),
                1,
                $itemCount
            );

            if ($violation !== null) {
                $validator->errors()->add('metadata', $violation);
            }
        });
    }

    private function nestedPayloadViolation(mixed $value, int $depth, int &$itemCount): ?string
    {
        if (is_string($value) && mb_strlen($value) > self::MAX_NESTED_STRING_CHARACTERS) {
            return 'Preview metadata strings may not exceed '.self::MAX_NESTED_STRING_CHARACTERS.' characters.';
        }

        if (! is_array($value)) {
            return null;
        }

        if ($depth > self::MAX_NESTING_DEPTH) {
            return 'Preview metadata may not exceed '.self::MAX_NESTING_DEPTH.' levels of nesting.';
        }

        if (count($value) > self::MAX_COLLECTION_ITEMS) {
            return 'Preview metadata collections, including block lists, may not contain more than '.self::MAX_COLLECTION_ITEMS.' items.';
        }

        $itemCount += count($value);

        if ($itemCount > self::MAX_NESTED_ITEMS) {
            return 'Preview metadata may not contain more than '.self::MAX_NESTED_ITEMS.' nested items.';
        }

        foreach ($value as $key => $nestedValue) {
            if (is_string($key) && mb_strlen($key) > self::MAX_NESTED_STRING_CHARACTERS) {
                return 'Preview metadata keys may not exceed '.self::MAX_NESTED_STRING_CHARACTERS.' characters.';
            }

            $violation = $this->nestedPayloadViolation($nestedValue, $depth + 1, $itemCount);

            if ($violation !== null) {
                return $violation;
            }
        }

        return null;
    }
}
