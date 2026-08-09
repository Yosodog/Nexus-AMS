<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CancelDataSyncRequest;
use App\Http\Requests\Admin\RunDataSyncRequest;
use App\Services\Settings\DataSyncSettings;
use Illuminate\Bus\Batch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\View\View;

class DataSyncSettingsController extends Controller
{
    public function __construct(private readonly DataSyncSettings $settings) {}

    public function index(): View
    {
        $this->authorize('view-diagnostic-info');

        $manualNationBatchId = $this->settings->getLastManualNationSyncBatchId();
        $rollingNationBatchId = $this->settings->getLastRollingNationSyncBatchId();
        $allianceBatchId = $this->settings->getLastAllianceSyncBatchId();
        $warBatchId = $this->settings->getLastWarSyncBatchId();

        $nationBatch = $manualNationBatchId ? Bus::findBatch($manualNationBatchId) : null;
        $rollingNationBatch = $rollingNationBatchId ? Bus::findBatch($rollingNationBatchId) : null;
        $allianceBatch = $allianceBatchId ? Bus::findBatch($allianceBatchId) : null;
        $warBatch = $warBatchId ? Bus::findBatch($warBatchId) : null;

        return view('admin.settings.data-sync', [
            'nationBatch' => $nationBatch,
            'rollingNationBatch' => $rollingNationBatch,
            'rollingSchedule' => $this->buildRollingScheduleContext($rollingNationBatch),
            'allianceBatch' => $allianceBatch,
            'warBatch' => $warBatch,
        ]);
    }

    public function runNation(RunDataSyncRequest $request): RedirectResponse
    {
        Artisan::call('sync:nations');

        return redirect()->route('admin.settings')->with([
            'alert-message' => 'Nation sync started.',
            'alert-type' => 'success',
        ]);
    }

    public function runAlliance(RunDataSyncRequest $request): RedirectResponse
    {
        Artisan::call('sync:alliances');

        return redirect()->route('admin.settings')->with([
            'alert-message' => 'Alliance sync started.',
            'alert-type' => 'success',
        ]);
    }

    public function runWar(RunDataSyncRequest $request): RedirectResponse
    {
        Artisan::call('sync:wars');

        return redirect()->route('admin.settings')->with([
            'alert-message' => 'War sync started.',
            'alert-type' => 'success',
        ]);
    }

    public function cancel(CancelDataSyncRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $batch = Bus::findBatch($validated['batch_id']);

        if ($batch && ! $batch->finished() && ! $batch->cancelled()) {
            $batch->cancel();
        }

        $typeLabel = match ($validated['type']) {
            'rolling_nation' => 'Rolling nation',
            default => ucfirst($validated['type']),
        };

        return redirect()->route('admin.settings')->with([
            'alert-message' => "{$typeLabel} sync cancelled.",
            'alert-type' => 'success',
        ]);
    }

    /** @return array<int|string, mixed> */
    private function buildRollingScheduleContext(?Batch $batch): array
    {
        $stepSeconds = $batch?->options['step_seconds'] ?? null;
        $scope = $batch?->options['scope'] ?? null;

        if (! $batch || ! $stepSeconds) {
            return [
                'scope' => $scope,
                'lastRunAt' => null,
                'nextRunAt' => null,
                'stepSeconds' => $stepSeconds,
            ];
        }

        $processed = $batch->processedJobs();
        $start = $batch->createdAt;

        $lastRunAt = $processed > 0
            ? $start->addSeconds($stepSeconds * max($processed - 1, 0))
            : null;

        $hasRemainingJobs = $processed < $batch->totalJobs && ! $batch->finished() && ! $batch->cancelled();
        $nextRunAt = $hasRemainingJobs
            ? $start->addSeconds($stepSeconds * $processed)
            : null;

        return [
            'scope' => $scope,
            'lastRunAt' => $lastRunAt,
            'nextRunAt' => $nextRunAt,
            'stepSeconds' => $stepSeconds,
        ];
    }
}
