<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Services\AllianceMembershipService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\View\View;

class CityController extends Controller
{
    use AuthorizesRequests;

    public function index(AllianceMembershipService $membershipService): View
    {
        $this->authorize('view-members');

        $cities = City::query()
            ->with(['nation:id,nation_name,leader_name,alliance_id'])
            ->whereHas('nation', function ($query) use ($membershipService) {
                $query->whereIn('alliance_id', $membershipService->getAllianceIds());
            })
            ->orderByDesc('powered')
            ->orderBy('nation_id')
            ->orderBy('id')
            ->get();

        $totalCities = $cities->count();
        $poweredCities = $cities->where('powered', true)->count();
        $unpoweredCities = $totalCities - $poweredCities;
        $misalignedInfrastructure = $cities->filter(fn (City $city) => ! $city->isInfrastructureAligned())->count();
        $misalignedLand = $cities->filter(fn (City $city) => ! $city->isLandAligned())->count();

        return view('admin.cities.index', [
            'cities' => $cities,
            'summary' => [
                'total_cities' => $totalCities,
                'powered_cities' => $poweredCities,
                'unpowered_cities' => $unpoweredCities,
                'average_infrastructure' => $cities->avg('infrastructure'),
                'average_land' => $cities->avg('land'),
                'misaligned_infrastructure' => $misalignedInfrastructure,
                'misaligned_land' => $misalignedLand,
            ],
        ]);
    }
}
