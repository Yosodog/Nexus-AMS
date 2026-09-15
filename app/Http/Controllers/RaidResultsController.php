<?php

namespace App\Http\Controllers;

use App\Models\RaidPrediction;
use App\Services\RaidAssessmentService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class RaidResultsController extends Controller
{
    public function index(Request $request, RaidAssessmentService $assessment): View
    {
        $nationId = (int) $request->user()->nation_id;
        abort_if($nationId < 1, 403);

        return view('defense.raid-results', [
            'predictions' => RaidPrediction::query()->where('attacker_nation_id', $nationId)
                ->with(['attacker', 'target'])->latest('declared_at')->paginate(25),
            'assessment' => $assessment->assess(attackerNationId: $nationId),
        ]);
    }

    public function admin(RaidAssessmentService $assessment): View
    {
        Gate::authorize('view-diagnostic-info');

        return view('admin.defense.raid-assessment', [
            'predictions' => RaidPrediction::query()->with(['attacker', 'target'])
                ->latest('declared_at')->paginate(50),
            'assessment' => $assessment->assess(),
        ]);
    }
}
