<?php

namespace App\Http\Requests\Alerts;

use App\Enums\AlertSubscriptionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAlertSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $eventNames = collect(AlertSubscriptionType::cases())
            ->flatMap(fn (AlertSubscriptionType $type): array => array_keys($type->events()))
            ->unique()
            ->values()
            ->all();

        return [
            'type' => ['required', Rule::enum(AlertSubscriptionType::class)],
            'name' => ['nullable', 'string', 'max:100'],
            'target_id' => [
                Rule::requiredIf(fn (): bool => in_array($this->input('type'), ['nation', 'alliance'], true)),
                'nullable',
                'integer',
                'min:1',
            ],
            'events' => [
                Rule::requiredIf(fn (): bool => in_array($this->input('type'), ['nation', 'alliance'], true)),
                'nullable',
                'array',
                'min:1',
            ],
            'events.*' => ['string', 'distinct', Rule::in($eventNames)],
            'resource' => [
                Rule::requiredIf(fn (): bool => $this->input('type') === 'market'),
                'nullable',
                Rule::in(array_keys(AlertSubscriptionType::resources())),
            ],
            'direction' => [
                Rule::requiredIf(fn (): bool => $this->input('type') === 'market'),
                'nullable',
                Rule::in(['above', 'below']),
            ],
            'threshold' => [
                Rule::requiredIf(fn (): bool => $this->input('type') === 'market'),
                'nullable',
                'numeric',
                'min:0.01',
                'max:1000000000',
            ],
            'cooldown_minutes' => ['nullable', 'integer', 'min:5', 'max:10080'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'events.required' => 'Choose at least one event to watch.',
            'target_id.required' => 'Enter the nation or alliance ID to watch.',
            'expires_at.after' => 'The expiration must be in the future.',
        ];
    }
}
