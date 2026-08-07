<?php

namespace App\Http\Requests\Admin;

class StoreStaffWorkQueueSavedViewRequest extends StaffWorkQueueRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'name' => ['required', 'string', 'max:60'],
        ]);
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $name = $this->input('name');
        $this->merge(['name' => is_string($name) ? trim($name) : $name]);
    }
}
