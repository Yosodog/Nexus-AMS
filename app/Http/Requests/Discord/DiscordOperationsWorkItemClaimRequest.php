<?php

namespace App\Http\Requests\Discord;

class DiscordOperationsWorkItemClaimRequest extends DiscordOperationsWorkItemShowRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'occurrence_key' => ['required', 'string', 'max:191', 'regex:/\A[a-z0-9][a-z0-9._:-]*\z/i'],
            'source_revision' => ['required', 'string', 'size:64', 'regex:/\A[a-f0-9]{64}\z/'],
            'lock_version' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'occurrence_key.regex' => 'The Operations occurrence key is invalid.',
            'source_revision.regex' => 'The Operations source revision is invalid.',
            'lock_version.min' => 'The coordination lock version must be at least 1.',
        ];
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        $this->merge([
            'occurrence_key' => $this->trimmedInput('occurrence_key'),
            'source_revision' => $this->trimmedInput('source_revision'),
        ]);
    }

    private function trimmedInput(string $key): mixed
    {
        $value = $this->input($key);

        return is_string($value) ? trim($value) : $value;
    }
}
