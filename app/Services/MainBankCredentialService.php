<?php

namespace App\Services;

use App\Models\MainBankCredential;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class MainBankCredentialService
{
    private bool $loaded = false;

    private ?MainBankCredential $credential = null;

    public function apiKey(): ?string
    {
        return $this->storedCredential('api_key') ?? $this->configuredCredential('api_key');
    }

    public function mutationKey(): ?string
    {
        return $this->storedCredential('mutation_key') ?? $this->configuredCredential('mutation_key');
    }

    /**
     * @return array{
     *     api_key_configured: bool,
     *     api_key_source: 'database'|'environment'|null,
     *     mutation_key_configured: bool,
     *     mutation_key_source: 'database'|'environment'|null
     * }
     */
    public function status(): array
    {
        $hasStoredApiKey = $this->storedCredential('api_key') !== null;
        $hasStoredMutationKey = $this->storedCredential('mutation_key') !== null;
        $hasConfiguredApiKey = $this->configuredCredential('api_key') !== null;
        $hasConfiguredMutationKey = $this->configuredCredential('mutation_key') !== null;

        return [
            'api_key_configured' => $hasStoredApiKey || $hasConfiguredApiKey,
            'api_key_source' => $hasStoredApiKey ? 'database' : ($hasConfiguredApiKey ? 'environment' : null),
            'mutation_key_configured' => $hasStoredMutationKey || $hasConfiguredMutationKey,
            'mutation_key_source' => $hasStoredMutationKey ? 'database' : ($hasConfiguredMutationKey ? 'environment' : null),
        ];
    }

    /**
     * @param  array{api_key?: string, mutation_key?: string}  $credentials
     */
    public function update(array $credentials): MainBankCredential
    {
        $credential = MainBankCredential::query()->find(MainBankCredential::SINGLETON_ID)
            ?? new MainBankCredential;

        $credential->setAttribute('id', MainBankCredential::SINGLETON_ID);
        $credential->fill($credentials);
        $credential->save();
        $credential->refresh();

        $this->credential = $credential;
        $this->loaded = true;

        return $this->credential;
    }

    private function credential(): ?MainBankCredential
    {
        if ($this->loaded) {
            return $this->credential;
        }

        $this->loaded = true;

        try {
            if (! Schema::hasTable('main_bank_credentials')) {
                return null;
            }

            return $this->credential = MainBankCredential::query()->find(MainBankCredential::SINGLETON_ID);
        } catch (Throwable $exception) {
            Log::warning('Unable to load stored main bank credentials; using environment configuration.', [
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function storedCredential(string $field): ?string
    {
        $credential = $this->credential();

        if (! $credential) {
            return null;
        }

        $value = $field === 'api_key'
            ? $credential->api_key_decrypted
            : $credential->mutation_key_decrypted;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function configuredCredential(string $field): ?string
    {
        $value = config("services.pw.{$field}");

        return is_string($value) && $value !== '' ? $value : null;
    }
}
