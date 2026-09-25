<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class RaidFinderController extends Controller
{
    public function index(Request $request): View
    {
        $ownNationId = (int) $request->user()->nation_id;
        $canQueryOthers = Gate::allows('view-raids');
        $nationId = $canQueryOthers && $request->integer('nation_id') > 0
            ? $request->integer('nation_id')
            : $ownNationId;

        return view('defense.raid-finder', [
            'nationId' => $nationId,
            'canQueryOthers' => $canQueryOthers,
            'finderEndpoint' => route('api.raid-finder.show', ['nation_id' => $nationId]),
            'availabilityEndpoint' => route('api.raid-finder.availability'),
            'claimsEndpoint' => route('api.raid-finder.claims.store'),
        ]);
    }
}
