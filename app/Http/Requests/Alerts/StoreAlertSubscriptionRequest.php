<?php

namespace App\Http\Requests\Alerts;

use App\Enums\AlertDeliveryMode;
use App\Enums\AlertSubscriptionType;
use App\Services\Alerts\AlertEventCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAlertSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(AlertEventCatalog $catalog): array
    {
        $eventNames = collect(AlertSubscriptionType::cases())
            ->flatMap(fn (AlertSubscriptionType $type): array => array_keys($type->events()))
            ->merge(array_keys($catalog->memberSubscriptionEvents()))
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
            'delivery_mode' => ['nullable', Rule::enum(AlertDeliveryMode::class)],
            'discord_enabled' => ['nullable', 'boolean'],
            'rearm_percent' => ['nullable', 'numeric', 'min:0.01', 'max:25'],
            'timezone' => ['nullable', 'string', 'max:64', 'timezone'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'submit_action' => ['nullable', Rule::in(['save', 'preview', 'test'])],
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
