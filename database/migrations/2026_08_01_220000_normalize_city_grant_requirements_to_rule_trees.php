<?php

use App\Services\GrantRequirementService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * @var array<int, string>
     */
    private const LEGACY_KEYS = [
        'min_cities',
        'max_cities',
        'min_score',
        'max_score',
        'min_mmr_score',
        'mmr_score',
        'max_mmr_score',
        'minimum_infra_per_city',
        'required_projects',
        'projects',
        'forbidden_projects',
        'allowed_colors',
        'government_type',
        'domestic_policy',
        'war_policy',
        'project_bits',
        'NRF',
        'irondome',
        'mmrScore',
        'infPerCity',
        'govSupportAgency',
        'bureauDomesticAffairs',
    ];

    /**
     * @var array<string, string>
     */
    private const LEGACY_PROJECT_FLAGS = [
        'NRF' => 'Nuclear Research Facility',
        'irondome' => 'Iron Dome',
        'govSupportAgency' => 'Government Support Agency',
        'bureauDomesticAffairs' => 'Bureau of Domestic Affairs',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $requirementService = app(GrantRequirementService::class);

        DB::transaction(function () use ($requirementService): void {
            DB::table('city_grants')
                ->select(['id', 'requirements'])
                ->orderBy('id')
                ->chunkById(100, function ($grants) use ($requirementService): void {
                    foreach ($grants as $grant) {
                        $definition = $this->decodeRequirements($grant->requirements, (int) $grant->id);
                        $definition = $this->prepareLegacyDefinition($definition, (int) $grant->id);
                        $inspection = $requirementService->inspect($definition);

                        if ($inspection['errors'] !== []) {
                            throw new RuntimeException(
                                "City grant {$grant->id} has invalid requirements: ".implode(' ', $inspection['errors'])
                            );
                        }

                        DB::table('city_grants')
                            ->where('id', $grant->id)
                            ->update([
                                'requirements' => $inspection['normalized'] === null
                                    ? null
                                    : json_encode($inspection['normalized'], JSON_THROW_ON_ERROR),
                            ]);
                    }
                });
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Intentionally irreversible because nested rule trees cannot be safely reduced to the legacy format.
    }

    /**
     * @return array<string, mixed>|array<int, mixed>|null
     */
    private function decodeRequirements(mixed $value, int $grantId): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        try {
            $decoded = json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("City grant {$grantId} has malformed JSON requirements.", previous: $exception);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException("City grant {$grantId} requirements must decode to an array.");
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>|array<int, mixed>|null  $definition
     * @return array<string, mixed>|array<int, mixed>|null
     */
    private function prepareLegacyDefinition(?array $definition, int $grantId): ?array
    {
        if ($definition === null || $definition === [] || array_is_list($definition)) {
            return $definition;
        }

        if (array_key_exists('group', $definition) || array_key_exists('field', $definition)) {
            return $definition;
        }

        $unsupported = collect(array_diff_key($definition, array_flip(self::LEGACY_KEYS)))
            ->reject(static fn (mixed $value): bool => $value === null || $value === '' || $value === [])
            ->keys()
            ->all();

        if ($unsupported !== []) {
            throw new RuntimeException(
                "City grant {$grantId} has unsupported legacy requirement keys: ".implode(', ', $unsupported)
            );
        }

        if (! isset($definition['min_mmr_score']) && array_key_exists('mmrScore', $definition)) {
            $definition['min_mmr_score'] = $definition['mmrScore'];
        }

        if (! isset($definition['minimum_infra_per_city']) && array_key_exists('infPerCity', $definition)) {
            $definition['minimum_infra_per_city'] = $definition['infPerCity'];
        }

        $requiredProjects = [];

        foreach (['required_projects', 'projects'] as $projectKey) {
            $projects = $definition[$projectKey] ?? [];

            if ($projects === null || $projects === '') {
                continue;
            }

            if (! is_array($projects)) {
                throw new RuntimeException(
                    "City grant {$grantId} has invalid legacy {$projectKey}; expected an array."
                );
            }

            $requiredProjects = [...$requiredProjects, ...$projects];
        }

        foreach (self::LEGACY_PROJECT_FLAGS as $key => $project) {
            if ($this->legacyFlagIsEnabled($definition[$key] ?? null, $key, $grantId)) {
                $requiredProjects[] = $project;
            }
        }

        unset(
            $definition['project_bits'],
            $definition['projects'],
            $definition['mmrScore'],
            $definition['infPerCity'],
            $definition['NRF'],
            $definition['irondome'],
            $definition['govSupportAgency'],
            $definition['bureauDomesticAffairs'],
        );

        $definition['required_projects'] = array_values(array_unique($requiredProjects));

        return $definition;
    }

    private function legacyFlagIsEnabled(mixed $value, string $key, int $grantId): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        if (is_bool($value)) {
            return $value;
        }

        if ($value === 0 || $value === '0') {
            return false;
        }

        if ($value === 1 || $value === '1') {
            return true;
        }

        if (is_string($value)) {
            $enabled = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if ($enabled !== null) {
                return $enabled;
            }
        }

        throw new RuntimeException(
            "City grant {$grantId} has invalid legacy {$key} flag; expected a boolean value."
        );
    }
};
