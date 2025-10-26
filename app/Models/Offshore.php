<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class Offshore extends Model
{
    protected $fillable = [
        'name',
        'alliance_id',
        'enabled',
        'priority',
        'api_key',
        'mutation_key',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'priority' => 'integer',
        'api_key' => 'encrypted',
        'mutation_key' => 'encrypted',
    ];

    protected $hidden = [
        'api_key',
        'mutation_key',
    ];

    /**
     * @return HasMany<OffshoreGuardrail>
     */
    public function guardrails(): HasMany
    {
        return $this->hasMany(OffshoreGuardrail::class);
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    public function getApiKeyDecryptedAttribute(): ?string
    {
        $value = $this->getRawOriginal('api_key');

        if (! $value) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException $exception) {
            Log::warning('Failed to decrypt offshore api key', [
                'offshore_id' => $this->id,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    public function getMutationKeyDecryptedAttribute(): ?string
    {
        $value = $this->getRawOriginal('mutation_key');

        if (! $value) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException $exception) {
            Log::warning('Failed to decrypt offshore mutation key', [
                'offshore_id' => $this->id,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    public function guardrailFor(string $resource): ?OffshoreGuardrail
    {
        if ($this->relationLoaded('guardrails')) {
            /** @var Collection<int, OffshoreGuardrail> $guardrails */
            $guardrails = $this->getRelation('guardrails');

            return $guardrails->firstWhere('resource', $resource);
        }

        return $this->guardrails()->where('resource', $resource)->first();
    }
}
