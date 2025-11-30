<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreIntelReportRequest;
use App\Models\IntelReport;
use App\Models\Nation;
use App\Services\IntelReportParser;
use App\Services\IntelReportService;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

class IntelReportController extends Controller
{
    /**
     * @return Factory|View|Application|RedirectResponse|object
     */
    public function index(Request $request)
    {
        $nationId = $request->integer('nation_id');
        $selectedNation = null;

        $query = IntelReport::with(['nation', 'user'])->latest();

        if ($nationId) {
            $selectedNation = Nation::find($nationId);

            if ($selectedNation) {
                $query->where(function ($builder) use ($selectedNation) {
                    $builder->where('nation_id', $selectedNation->id)
                        ->orWhere('nation_name', $selectedNation->nation_name);
                });
            } else {
                return redirect()
                    ->route('defense.intel')
                    ->with(['alert-message' => 'Nation not found.', 'alert-type' => 'error']);
            }
        }

        $reports = $query->paginate(15)->appends($request->only('nation_id'));

        return view('defense.intel', [
            'reports' => $reports,
            'selectedNation' => $selectedNation,
            'nationId' => $nationId,
        ]);
    }

    /**
     * @return RedirectResponse
     */
    public function store(
        StoreIntelReportRequest $request,
        IntelReportParser $parser,
        IntelReportService $service
    ) {
        try {
            $parsed = $parser->parse($request->input('report'));
        } catch (InvalidArgumentException $exception) {
            return redirect()->route('defense.intel')->withInput()->with([
                'alert-message' => $exception->getMessage(),
                'alert-type' => 'error',
            ]);
        }

        $service->store($parsed, $request->input('source', 'web'), Auth::id());

        return redirect()->route('defense.intel')->with([
            'alert-message' => 'Intel saved and shared with the alliance.',
            'alert-type' => 'success',
        ]);
    }
}
