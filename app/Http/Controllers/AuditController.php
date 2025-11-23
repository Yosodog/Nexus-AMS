<?php

namespace App\Http\Controllers;

use App\Enums\AuditPriority;
use App\Services\Audit\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuditController extends Controller
{
    public function __construct(private readonly AuditService $auditService) {}

    public function index(): View|RedirectResponse
    {
        $user = Auth::user();
        $nation = $user?->nation;

        if (! $nation) {
            return redirect()->route('user.dashboard')->with([
                'alert-message' => 'Link a nation to view audit results.',
                'alert-type' => 'error',
            ]);
        }

        $nation->load('cities');

        $violations = $this->auditService->getNationAndCityViolationsForNation($nation);
        $allViolations = $violations['nation']->concat($violations['cities']);

        $grouped = $allViolations->groupBy(function ($result): string {
            return $result->rule?->priority->value ?? AuditPriority::Info->value;
        });

        $priorityOrder = [
            AuditPriority::High,
            AuditPriority::Medium,
            AuditPriority::Low,
            AuditPriority::Info,
        ];

        return view('audit.index', [
            'nation' => $nation,
            'violationsByPriority' => $grouped,
            'priorityOrder' => $priorityOrder,
        ]);
    }
}
