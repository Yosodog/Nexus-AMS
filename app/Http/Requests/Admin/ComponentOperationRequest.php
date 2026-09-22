<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ComponentOperationRequest extends FormRequest
{
    /** @var string[] */
    protected $dontFlash = ['configuration'];

    public function authorize(): bool
    {
        $user = $this->user();

        return (bool) ($user?->is_admin && ! $user->disabled && $user->can('manage-system'));
    }

    public function rules(): array
    {
        $acceptsConfiguration = $this->route('component') === 'nexus-discord'
            && $this->route('action') === 'install';

        return [
            'confirmation' => ['required', 'accepted'],
            'configuration' => [Rule::requiredIf($acceptsConfiguration), Rule::prohibitedIf(! $acceptsConfiguration), 'array', 'max:3'],
            'configuration.bot_token' => [Rule::requiredIf($acceptsConfiguration), 'string', 'max:512', 'not_regex:/[\x00-\x1F\x7F]/'],
            'configuration.client_id' => [Rule::requiredIf($acceptsConfiguration), 'string', 'regex:/\A[0-9]{17,20}\z/D'],
            'configuration.guild_id' => [Rule::requiredIf($acceptsConfiguration), 'string', 'regex:/\A[0-9]{17,20}\z/D'],
            'command' => ['prohibited'],
            'path' => ['prohibited'],
            'repository' => ['prohibited'],
            'service' => ['prohibited'],
            'target_release_id' => ['prohibited'],
            'url' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $component = (string) $this->route('component');
            $action = (string) $this->route('action');

            if ($component === 'nexus-core' && $action !== 'restart') {
                $validator->errors()->add('component', 'Nexus Core can only be restarted from this page.');
            }

            if ($component === 'nexus-subs' && $action === 'install' && $this->has('configuration')) {
                $validator->errors()->add('configuration', 'Local Subs reuses the Nexus Core configuration automatically.');
            }
        });
    }
}
