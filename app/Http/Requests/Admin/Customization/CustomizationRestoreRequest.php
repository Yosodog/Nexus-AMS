<?php

namespace App\Http\Requests\Admin\Customization;

use Illuminate\Foundation\Http\FormRequest;

class CustomizationRestoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-custom-pages') ?? false;
    }

    public function rules(): array
    {
        return [
            'version_id' => ['required', 'integer', 'exists:page_versions,id'],
            'publish' => ['sometimes', 'boolean'],
        ];
    }

    public function versionId(): int
    {
        return (int) $this->input('version_id');
    }

    public function shouldPublish(): bool
    {
        return $this->boolean('publish');
    }
}
