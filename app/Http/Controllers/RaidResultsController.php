<?php

namespace App\Http\Controllers;

use App\Models\RaidPrediction;
use App\Services\RaidAssessmentService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class RaidResultsController extends Controller
{
    private const LIST_COLUMNS = [
        'id',
        'war_id',
        'attacker_nation_id',
        'target_nation_id',
        'declared_at',
        'expected_net',
        'expected_net_low',
        'expected_net_high',
        'actual_net',
        'outcome_status',
        'capture_status',
        'confidence',
        'finder_rank',
        'model_version',
    ];

    public function index(Request $request, RaidAssessmentService $assessment): View
    {
        $nationId = (int) $request->user()->nation_id;
        abort_if($nationId < 1, 403);

        return view('defense.raid-results', [
            'predictions' => RaidPrediction::query()->select(self::LIST_COLUMNS)->where('attacker_nation_id', $nationId)
                ->with(['attacker', 'target'])->latest('declared_at')->paginate(25),
            'assessment' => $assessment->assess(attackerNationId: $nationId),
        ]);
    }

    public function admin(RaidAssessmentService $assessment): View
    {
        Gate::authorize('view-diagnostic-info');

        return view('admin.defense.raid-assessment', [
            'predictions' => RaidPrediction::query()->select(self::LIST_COLUMNS)->with(['attacker', 'target'])
                ->latest('declared_at')->paginate(50),
            'assessment' => $assessment->assess(),
        ]);
    }
}
