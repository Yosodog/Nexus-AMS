<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Services\AllianceMembershipService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CityController extends Controller
{
    use AuthorizesRequests;

    private const int CITIES_PER_PAGE = 100;

    /**
     * @var array<string, string>
     */
    private const array SORT_COLUMNS = [
        'city' => 'cities.name',
        'nation' => 'sort_nations.leader_name',
        'alliance' => 'sort_alliances.name',
        'founded' => 'cities.date',
        'infrastructure' => 'cities.infrastructure',
        'land' => 'cities.land',
        'power' => 'cities.powered',
        'oil_power' => 'cities.oil_power',
        'wind_power' => 'cities.wind_power',
        'coal_power' => 'cities.coal_power',
        'nuclear_power' => 'cities.nuclear_power',
        'coal_mine' => 'cities.coal_mine',
        'oil_well' => 'cities.oil_well',
        'uranium_mine' => 'cities.uranium_mine',
        'lead_mine' => 'cities.lead_mine',
        'iron_mine' => 'cities.iron_mine',
        'bauxite_mine' => 'cities.bauxite_mine',
        'barracks' => 'cities.barracks',
        'farm' => 'cities.farm',
        'police_station' => 'cities.police_station',
        'hospital' => 'cities.hospital',
        'recycling_center' => 'cities.recycling_center',
        'subway' => 'cities.subway',
        'supermarket' => 'cities.supermarket',
        'bank' => 'cities.bank',
        'shopping_mall' => 'cities.shopping_mall',
        'stadium' => 'cities.stadium',
        'oil_refinery' => 'cities.oil_refinery',
        'aluminum_refinery' => 'cities.aluminum_refinery',
        'steel_mill' => 'cities.steel_mill',
        'munitions_factory' => 'cities.munitions_factory',
        'factory' => 'cities.factory',
        'hangar' => 'cities.hangar',
        'drydock' => 'cities.drydock',
        'updated' => 'cities.updated_at',
    ];

    public function index(Request $request, AllianceMembershipService $membershipService): View
    {
        $this->authorize('view-members');

        $requestedSort = (string) $request->query('sort', 'power');
        $sort = array_key_exists($requestedSort, self::SORT_COLUMNS) ? $requestedSort : 'power';
        $requestedDirection = strtolower((string) $request->query('direction', $sort === 'power' ? 'desc' : 'asc'));
        $direction = in_array($requestedDirection, ['asc', 'desc'], true) ? $requestedDirection : 'asc';

        $cityQuery = $this->cityQuery($membershipService);
        $summaryRecord = (clone $cityQuery)
            ->toBase()
            ->selectRaw(<<<'SQL'
                COUNT(*) AS total_cities,
                SUM(CASE WHEN powered = 1 THEN 1 ELSE 0 END) AS powered_cities,
                COALESCE(AVG(infrastructure), 0) AS average_infrastructure,
                COALESCE(AVG(land), 0) AS average_land,
                SUM(CASE WHEN ABS(infrastructure - ROUND(infrastructure / 50) * 50) >= 0.01 THEN 1 ELSE 0 END) AS misaligned_infrastructure,
                SUM(CASE WHEN ABS(land - ROUND(land / 50) * 50) >= 0.01 THEN 1 ELSE 0 END) AS misaligned_land
                SQL)
            ->first();

        $totalCities = (int) $summaryRecord->total_cities;
        $poweredCities = (int) $summaryRecord->powered_cities;

        $cities = $this->applySort((clone $cityQuery), $sort, $direction)
            ->with([
                'nation' => fn ($query) => $query
                    ->select('id', 'nation_name', 'leader_name', 'alliance_id', 'alliance_position', 'vacation_mode_turns')
                    ->with(['alliance:id,name,acronym']),
            ])
            ->paginate(
                perPage: self::CITIES_PER_PAGE,
                columns: ['cities.*'],
                total: $totalCities,
            )
            ->withQueryString();

        return view('admin.cities.index', [
            'cities' => $cities,
            'sort' => $sort,
            'direction' => $direction,
            'summary' => [
                'total_cities' => $totalCities,
                'powered_cities' => $poweredCities,
                'unpowered_cities' => $totalCities - $poweredCities,
                'average_infrastructure' => (float) $summaryRecord->average_infrastructure,
                'average_land' => (float) $summaryRecord->average_land,
                'misaligned_infrastructure' => (int) $summaryRecord->misaligned_infrastructure,
                'misaligned_land' => (int) $summaryRecord->misaligned_land,
            ],
        ]);
    }

    private function cityQuery(AllianceMembershipService $membershipService): Builder
    {
        return City::query()
            ->whereHas('nation', function (Builder $query) use ($membershipService): void {
                $query->whereIn('alliance_id', $membershipService->getAllianceIds())
                    ->where(function (Builder $query): void {
                        $query->whereNull('alliance_position')
                            ->orWhere('alliance_position', '!=', 'APPLICANT');
                    })
                    ->where(function (Builder $query): void {
                        $query->whereNull('vacation_mode_turns')
                            ->orWhere('vacation_mode_turns', '<=', 0);
                    });
            });
    }

    private function applySort(Builder $query, string $sort, string $direction): Builder
    {
        if ($sort === 'nation') {
            $query->leftJoin('nations as sort_nations', 'sort_nations.id', '=', 'cities.nation_id');
        }

        if ($sort === 'alliance') {
            $query->leftJoin('nations as sort_nations', 'sort_nations.id', '=', 'cities.nation_id')
                ->leftJoin('alliances as sort_alliances', 'sort_alliances.id', '=', 'sort_nations.alliance_id');
        }

        return $query
            ->orderBy(self::SORT_COLUMNS[$sort], $direction)
            ->orderBy('cities.nation_id')
            ->orderBy('cities.id');
    }
}
