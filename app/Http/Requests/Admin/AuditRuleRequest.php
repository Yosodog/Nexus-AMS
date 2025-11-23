<?php

namespace App\Http\Requests\Admin;

use App\Enums\AuditPriority;
use App\Enums\AuditTargetType;
use App\Nel\NelParser;
use App\Nel\NelValidator;
use App\Services\Audit\AuditVariableRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Throwable;

class AuditRuleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'target_type' => ['required', 'string', Rule::in(AuditTargetType::values())],
            'priority' => ['required', 'string', Rule::in(AuditPriority::values())],
            'expression' => ['required', 'string'],
            'enabled' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'expression' => $this->input('expression') !== null ? trim((string) $this->input('expression')) : null,
        ]);
    }

    /**
     * Perform NEL syntax validation after basic rules pass.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                try {
                    $expression = (string) $this->string('expression');
                    $targetType = AuditTargetType::tryFrom((string) $this->input('target_type'));

                    app(NelParser::class)->parse($expression);

                    if ($targetType !== null) {
                        $allowed = app(AuditVariableRegistry::class)->allowedFor($targetType);
                        app(NelValidator::class)->assertAllowedIdentifiers($expression, $allowed);
                    }
                } catch (Throwable $exception) {
                    $validator->errors()->add('expression', 'NEL syntax error: '.$exception->getMessage());
                }
            },
        ];
    }
}
