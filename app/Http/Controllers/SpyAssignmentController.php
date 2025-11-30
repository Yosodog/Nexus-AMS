<?php

namespace App\Http\Controllers;

use App\Models\SpyAssignment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class SpyAssignmentController extends Controller
{
    public function index(): View|RedirectResponse
    {
        Gate::authorize('view-spies');

        $nationId = auth()->user()?->nation_id;

        if (! $nationId) {
            return redirect()->route('user.dashboard')
                ->with('alert-type', 'warning')
                ->with('alert-message', 'Link your nation to view spy assignments.');
        }

        $assignments = SpyAssignment::query()
            ->with(['round.campaign', 'defender'])
            ->where('attacker_nation_id', $nationId)
            ->latest()
            ->get();

        return view('spy.assignments', [
            'assignments' => $assignments,
        ]);
    }
}
