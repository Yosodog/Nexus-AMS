<?php

namespace App\Http\Requests\Admin;

use App\Enums\BackupDestination;
use App\Support\BackupSchedule;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBackupConfigurationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('view-diagnostic-info') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            's3_bucket' => trim((string) $this->input('s3_bucket')),
            's3_region' => trim((string) $this->input('s3_region')),
            's3_endpoint' => trim((string) $this->input('s3_endpoint')) ?: null,
            's3_folder' => trim(trim((string) $this->input('s3_folder')), '/') ?: null,
            'schedule_cron' => trim((string) $this->input('schedule_cron')) ?: null,
            's3_path_style' => $this->boolean('s3_path_style'),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $usesS3 = Rule::requiredIf(fn (): bool => BackupDestination::tryFrom((string) $this->input('destination'))?->usesS3() ?? false);

        return [
            'schedule_preset' => ['required', Rule::in([...array_keys(BackupSchedule::PRESETS), 'custom'])],
            'schedule_cron' => [
                'nullable',
                'required_if:schedule_preset,custom',
                'string',
                'max:100',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && ! BackupSchedule::isValid($value)) {
                        $fail('Enter a valid five-field cron expression, such as "30 1 * * *".');
                    }
                },
            ],
            'destination' => ['required', Rule::enum(BackupDestination::class)],
            's3_bucket' => [$usesS3, 'nullable', 'string', 'min:3', 'max:63', 'regex:/^[a-z0-9][a-z0-9.\-]*[a-z0-9]$/'],
            's3_region' => [$usesS3, 'nullable', 'string', 'max:40', 'regex:/^[a-z0-9\-]+$/'],
            's3_endpoint' => ['nullable', 'url:https', 'max:255'],
            's3_path_style' => ['boolean'],
            's3_folder' => ['nullable', 'string', 'max:200', 'regex:/^[A-Za-z0-9 _.\-\/]+$/'],
            's3_access_key_id' => ['nullable', 'string', 'max:128'],
            's3_secret_access_key' => ['nullable', 'string', 'max:256'],
            'archive_password' => ['nullable', 'string', 'min:12', 'max:256', 'confirmed'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'schedule_cron.required_if' => 'Enter a cron expression for a custom schedule.',
            's3_bucket.required' => 'Enter the S3 bucket name.',
            's3_bucket.regex' => 'Enter only the bucket name (lowercase letters, numbers, dots and dashes). Put any folder in the Folder field.',
            's3_region.required' => 'Enter the bucket region, such as us-east-1.',
            's3_region.regex' => 'Enter a region such as us-east-1 (or "auto" for Cloudflare R2).',
            's3_endpoint.url' => 'The endpoint must be an https:// URL.',
            's3_folder.regex' => 'The folder may contain letters, numbers, spaces, dots, dashes, underscores and slashes.',
            'archive_password.min' => 'Use an archive password of at least 12 characters.',
            'archive_password.confirmed' => 'The archive passwords do not match.',
        ];
    }
}
