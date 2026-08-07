<?php

namespace App\Http\Requests\Admin;

use App\Services\StaffWorkQueue\StaffWorkQueueFilterSet;
use App\Services\StaffWorkQueue\StaffWorkQueueRegistry;
use Illuminate\Foundation\Http\FormRequest;

class StaffWorkQueueRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $this->allowedTypes() !== [];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return array_merge(StaffWorkQueueFilterSet::rules(array_keys($this->allowedTypes())), [
            'saved_view' => ['nullable', 'uuid'],
            'page' => ['nullable', 'integer', 'min:1'],
            'refresh' => ['nullable', 'boolean'],
        ]);
    }

    public function filters(): StaffWorkQueueFilterSet
    {
        return StaffWorkQueueFilterSet::fromArray(
            $this->safe()->only(['q', 'type', 'urgency', 'owner', 'sort', 'direction']),
            array_keys($this->allowedTypes()),
        );
    }

    /**
     * @return array<string, string>
     */
    public function allowedTypes(): array
    {
        $user = $this->user();

        if ($user === null) {
            return [];
        }

        return app(StaffWorkQueueRegistry::class)->allowedTypes($user);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(collect(['q', 'type', 'urgency', 'owner', 'sort', 'direction', 'saved_view'])
            ->mapWithKeys(function (string $key): array {
                $value = $this->input($key);

                return [$key => is_string($value) ? trim($value) : $value];
            })
            ->all());
    }
}
