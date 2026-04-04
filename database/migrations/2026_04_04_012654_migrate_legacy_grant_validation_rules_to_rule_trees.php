<?php

use App\Services\GrantRequirementService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        /** @var GrantRequirementService $grantRequirementService */
        $grantRequirementService = app(GrantRequirementService::class);

        DB::table('grants')
            ->select(['id', 'validation_rules'])
            ->orderBy('id')
            ->each(function (object $grant) use ($grantRequirementService): void {
                $decodedRules = $this->decodeValidationRules($grant->validation_rules);
                $normalizedRules = $grantRequirementService->normalize($decodedRules);

                DB::table('grants')
                    ->where('id', $grant->id)
                    ->update([
                        'validation_rules' => $normalizedRules === null
                            ? null
                            : json_encode($normalizedRules, JSON_THROW_ON_ERROR),
                    ]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Intentionally left blank because the legacy rule format is lossy once normalized.
    }

    /**
     * @return array<string, mixed>|array<int, mixed>|null
     */
    private function decodeValidationRules(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        try {
            $decodedValue = json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decodedValue) ? $decodedValue : null;
    }
};
