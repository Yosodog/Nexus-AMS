<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class MainBankCredential extends Model
{
    public const SINGLETON_ID = 1;

    public $incrementing = false;

    protected $fillable = [
        'api_key',
        'mutation_key',
    ];

    protected $hidden = [
        'api_key',
        'mutation_key',
    ];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'api_key' => 'encrypted',
            'mutation_key' => 'encrypted',
        ];
    }

    public function getApiKeyDecryptedAttribute(): ?string
    {
        return $this->decryptCredential('api_key');
    }

    public function getMutationKeyDecryptedAttribute(): ?string
    {
        return $this->decryptCredential('mutation_key');
    }

    private function decryptCredential(string $field): ?string
    {
        $value = $this->getRawOriginal($field);

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException $exception) {
            Log::warning('Failed to decrypt main bank credential', [
                'credential' => $field,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }
}
