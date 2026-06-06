<?php

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Translation\PotentiallyTranslatedString;

class UniqueCanonicalUsername implements ValidationRule
{
    public function __construct(private readonly ?int $ignoreUserId = null) {}

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $exists = User::query()
            ->whereRaw('LOWER(name) = ?', [Str::lower((string) $value)])
            ->when($this->ignoreUserId !== null, function (Builder $query): void {
                $query->whereKeyNot($this->ignoreUserId);
            })
            ->exists();

        if ($exists) {
            $fail('The username has already been taken.');
        }
    }
}
