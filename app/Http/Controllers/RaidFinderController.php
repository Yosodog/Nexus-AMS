<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RaidFinderController extends Controller
{
    public function index(Request $request): View
    {
        $nationId = $request->get('nation_id') ?? Auth::user()->nation_id;

        return view('defense.raid-finder', [
            'nationId' => $nationId,
        ]);
    }
}
