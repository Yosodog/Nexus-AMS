<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateMarketResourceRequest;
use App\Models\MarketResource;
use App\Models\MarketTransaction;
use App\Services\AuditLogger;
use App\Services\MarketService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class MarketController extends Controller
{
    public function index(MarketService $marketService): View
    {
        Gate::authorize('view-market');

        $resources = $marketService->getMarketResources();
        $marketResources = $marketService->getMarketResourcePricing($resources);
        $overview = $marketService->getAdminMarketOverview();

        $recentTransactions = MarketTransaction::query()
            ->with(['user', 'nation', 'account'])
            ->latest()
            ->limit(50)
            ->get();

        return view('admin.market.index', [
            'marketResources' => $marketResources,
            'overview' => $overview,
            'recentTransactions' => $recentTransactions,
        ]);
    }

    public function toggle(MarketResource $marketResource, AuditLogger $auditLogger): RedirectResponse
    {
        Gate::authorize('manage-market');

        $before = $marketResource->is_enabled;
        $marketResource->is_enabled = ! $marketResource->is_enabled;
        $marketResource->save();

        $auditLogger->recordAfterCommit(
            category: 'market',
            action: 'resource_toggled',
            outcome: 'success',
            severity: 'info',
            subject: $marketResource,
            context: [
                'changes' => [
                    'is_enabled' => [
                        'before' => $before,
                        'after' => $marketResource->is_enabled,
                    ],
                ],
            ],
            message: 'Alliance market resource toggled.'
        );

        return redirect()->to($this->backToResources())->with([
            'alert-message' => 'Market resource status updated.',
            'alert-type' => 'success',
        ]);
    }

    public function update(UpdateMarketResourceRequest $request, MarketResource $marketResource, AuditLogger $auditLogger): RedirectResponse
    {
        Gate::authorize('manage-market');

        $before = [
            'adjustment_percent' => (float) $marketResource->adjustment_percent,
            'buy_cap_remaining' => (float) $marketResource->buy_cap_remaining,
        ];

        $marketResource->update($request->validated());

        $auditLogger->recordAfterCommit(
            category: 'market',
            action: 'resource_updated',
            outcome: 'success',
            severity: 'info',
            subject: $marketResource,
            context: [
                'changes' => [
                    'adjustment_percent' => [
                        'before' => $before['adjustment_percent'],
                        'after' => (float) $marketResource->adjustment_percent,
                    ],
                    'buy_cap_remaining' => [
                        'before' => $before['buy_cap_remaining'],
                        'after' => (float) $marketResource->buy_cap_remaining,
                    ],
                ],
            ],
            message: 'Alliance market resource updated.'
        );

        return redirect()->to($this->backToResources())->with([
            'alert-message' => 'Market resource updated.',
            'alert-type' => 'success',
        ]);
    }

    private function backToResources(): string
    {
        return url()->previous().'#market-resources';
    }
}
