<?php

namespace App\Services\Audit;

use App\Enums\AuditTargetType;
use App\Models\City;
use App\Models\Nation;
use App\Services\AllianceMembershipService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

final class AuditContextLoader
{
    public function __construct(
        private readonly AllianceMembershipService $membershipService,
        private readonly AuditFieldRegistry $fields,
        private readonly AuditRuleDefinitionService $definitions,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $definitions
     */
    public function query(AuditTargetType $targetType, array $definitions): Builder
    {
        $fieldKeys = [];

        foreach ($definitions as $definition) {
            $fieldKeys = [...$fieldKeys, ...$this->definitions->referencedFields($definition)];
        }

        $dependencies = $this->fields->dependenciesFor($targetType, array_values(array_unique($fieldKeys)));
        $allianceIds = $this->membershipService->getAllianceIds()->all();

        return match ($targetType) {
            AuditTargetType::Nation => $this->nationQuery($allianceIds, $dependencies),
            AuditTargetType::City => $this->cityQuery($allianceIds, $dependencies),
        };
    }

    /**
     * @param  array<int, int>  $allianceIds
     * @param  array{columns: array<int, string>, relations: array<string, array<int, string>>}  $dependencies
     */
    private function nationQuery(array $allianceIds, array $dependencies): Builder
    {
        $query = $this->applyMemberConstraints(Nation::query(), $allianceIds)
            ->select($dependencies['columns']);

        foreach ($dependencies['relations'] as $relation => $columns) {
            $query->with([$relation => static function (Builder|Relation $query) use ($columns): void {
                self::selectRelationshipColumns($query, $columns);
            }]);
        }

        return $query;
    }

    /**
     * @param  array<int, int>  $allianceIds
     * @param  array{columns: array<int, string>, relations: array<string, array<int, string>>}  $dependencies
     */
    private function cityQuery(array $allianceIds, array $dependencies): Builder
    {
        $query = City::query()
            ->select($dependencies['columns'])
            ->whereHas('nation', function (Builder $query) use ($allianceIds): void {
                $this->applyMemberConstraints($query, $allianceIds);
            });

        $nationColumns = array_values(array_unique([
            'id',
            'nation_name',
            'leader_name',
            ...($dependencies['relations']['nation'] ?? []),
        ]));

        $query->with(['nation' => static function (Builder|Relation $query) use ($nationColumns): void {
            self::selectRelationshipColumns($query, $nationColumns);
        }]);

        return $query;
    }

    /**
     * @param  array<int, string>  $columns
     */
    private static function selectRelationshipColumns(Builder|Relation $query, array $columns): void
    {
        $model = $query instanceof Relation ? $query->getRelated() : $query->getModel();

        $query->select(array_map(
            static fn (string $column): string => str_contains($column, '.')
                ? $column
                : $model->qualifyColumn($column),
            $columns,
        ));
    }

    /**
     * @param  array<int, int>  $allianceIds
     */
    public function applyMemberConstraints(Builder $query, array $allianceIds): Builder
    {
        return $query
            ->whereIn('alliance_id', $allianceIds)
            ->where(function (Builder $query): void {
                $query->whereNull('alliance_position')
                    ->orWhere('alliance_position', '!=', 'APPLICANT');
            })
            ->where(function (Builder $query): void {
                $query->whereNull('vacation_mode_turns')
                    ->orWhere('vacation_mode_turns', '<=', 0);
            });
    }
}
