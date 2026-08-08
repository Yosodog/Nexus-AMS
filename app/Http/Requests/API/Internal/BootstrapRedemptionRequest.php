<?php

declare(strict_types=1);

namespace App\Http\Requests\API\Internal;

use App\Actions\Fortify\PasswordValidationRules;
use App\DataTransferObjects\BootstrapLocalIdentity;
use App\Http\Middleware\CaptureBootstrapTokenHash;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class BootstrapRedemptionRequest extends FormRequest
{
    use PasswordValidationRules;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'bootstrap_token_valid' => ['accepted'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => $this->passwordRules(),
        ];
    }

    public function identity(): BootstrapLocalIdentity
    {
        return new BootstrapLocalIdentity(
            name: (string) $this->validated('name'),
            email: (string) $this->validated('email'),
            password: (string) $this->validated('password'),
        );
    }

    public function scrubCredentials(): void
    {
        foreach (['password', 'password_confirmation'] as $field) {
            $this->request->remove($field);
            $this->json()->remove($field);
        }
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'bootstrap_token_valid' => $this->attributes->get(
                CaptureBootstrapTokenHash::VALID_ATTRIBUTE,
            ) === true,
        ]);
    }
}
