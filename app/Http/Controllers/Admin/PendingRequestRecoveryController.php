<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReleaseStalePendingRequestsRequest;
use App\Services\Admin\PendingRequestRecoveryService;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class PendingRequestRecoveryController extends Controller
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly PendingRequestRecoveryService $recovery,
    ) {}

    public function index(): View
    {
        $this->authorize('view-diagnostic-info');

        return view('admin.settings.recovery', [
            'stalePendingDefaultHours' => PendingRequestRecoveryService::DEFAULT_STALE_PENDING_HOURS,
            'pendingRecoveryItems' => $this->recovery->summaries(),
        ]);
    }

    public function store(ReleaseStalePendingRequestsRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $result = $this->recovery->release(
            (string) $validated['type'],
            (int) $validated['older_than_hours'],
        );

        $this->auditLogger->success(
            category: 'settings',
            action: 'stale_pending_requests_released',
            context: [
                'data' => [
                    'type' => $result['type'],
                    'label' => $result['label'],
                    'older_than_hours' => $result['olderThanHours'],
                    'released_count' => $result['releasedCount'],
                    'cutoff' => $result['cutoff']->toIso8601String(),
                    'operator_confirmed' => true,
                ],
            ],
            message: 'Stuck pending requests closed.'
        );

        $message = $result['releasedCount'] > 0
            ? "Closed {$result['releasedCount']} stuck {$result['label']} requests older than {$result['olderThanHours']} hours."
            : "No stuck {$result['label']} requests older than {$result['olderThanHours']} hours were found.";

        return redirect()->route('admin.settings')->with([
            'alert-message' => $message,
            'alert-type' => 'success',
        ]);
    }
}
