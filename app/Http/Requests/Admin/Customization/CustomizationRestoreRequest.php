<?php

namespace App\Http\Requests\Admin\Customization;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validate restore requests originating from the customization editor.
 */
class CustomizationRestoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-custom-pages') ?? false;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'version_id' => ['required', 'integer', 'exists:page_versions,id'],
            'publish' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Retrieve the target version identifier.
     */
    public function versionId(): int
    {
        return (int) $this->input('version_id');
    }

    /**
     * Determine whether the restored version should be published immediately.
     */
    public function shouldPublish(): bool
    {
        return $this->boolean('publish');
    }
}
