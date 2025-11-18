<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\SettingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Bus\Batch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\View\View;

class SettingsController extends Controller
{
    /**
     * @throws AuthorizationException
     */
    public function index(): View
    {
        $this->authorize('view-diagnostic-info');

        $manualNationBatchId = SettingService::getLastManualNationSyncBatchId();
        $rollingNationBatchId = SettingService::getLastRollingNationSyncBatchId();
        $allianceBatchId = SettingService::getLastAllianceSyncBatchId();
        $warBatchId = SettingService::getLastWarSyncBatchId();

        $nationBatch = $manualNationBatchId ? Bus::findBatch($manualNationBatchId) : null;
        $rollingNationBatch = $rollingNationBatchId ? Bus::findBatch($rollingNationBatchId) : null;
        $allianceBatch = $allianceBatchId ? Bus::findBatch($allianceBatchId) : null;
        $warBatch = $warBatchId ? Bus::findBatch($warBatchId) : null;

        $appName = config('app.name', 'Nexus AMS');
        $homepageSettings = [
            'headline' => SettingService::getHomepageHeadline($appName),
            'tagline' => SettingService::getHomepageTagline($appName),
            'about' => SettingService::getHomepageAbout($appName),
            'highlights' => SettingService::getHomepageHighlights(),
        ];

        return view('admin.settings', [
            'nationBatch' => $nationBatch,
            'rollingNationBatch' => $rollingNationBatch,
            'rollingSchedule' => $this->buildRollingScheduleContext($rollingNationBatch),
            'allianceBatch' => $allianceBatch,
            'warBatch' => $warBatch,
            'discordVerificationRequired' => SettingService::isDiscordVerificationRequired(),
            'homepageSettings' => $homepageSettings,
        ]);
    }

    public function runSyncNation(): RedirectResponse
    {
        $this->authorize('view-diagnostic-info');

        Artisan::call('sync:nations');

        return redirect()->route('admin.settings')->with([
            'alert-message' => 'Nation sync command dispatched.',
            'alert-type' => 'success',
        ]);
    }

    public function runSyncAlliance(): RedirectResponse
    {
        $this->authorize('view-diagnostic-info');

        Artisan::call('sync:alliances');

        return redirect()->route('admin.settings')->with([
            'alert-message' => 'Alliance sync command dispatched.',
            'alert-type' => 'success',
        ]);
    }

    public function runSyncWar(): RedirectResponse
    {
        $this->authorize('view-diagnostic-info');

        Artisan::call('sync:wars');

        return redirect()->route('admin.settings')->with([
            'alert-message' => 'War sync command dispatched.',
            'alert-type' => 'success',
        ]);
    }

    public function cancelSync(Request $request): RedirectResponse
    {
        $this->authorize('view-diagnostic-info');

        $request->validate([
            'batch_id' => 'required|string',
            'type' => 'required|in:nation,rolling_nation,alliance,war',
        ]);

        $batch = Bus::findBatch($request->input('batch_id'));

        if ($batch && ! $batch->finished() && ! $batch->cancelled()) {
            $batch->cancel();
        }

        $typeLabel = match ($request->input('type')) {
            'rolling_nation' => 'Rolling nation',
            default => ucfirst($request->input('type')),
        };

        $message = "{$typeLabel} sync cancelled.";

        return redirect()->route('admin.settings')->with([
            'alert-message' => $message,
            'alert-type' => 'success',
        ]);
    }

    public function updateDiscordRequirement(Request $request): RedirectResponse
    {
        $this->authorize('view-diagnostic-info');

        $validated = $request->validate([
            'require_discord_verification' => ['required', 'boolean'],
        ]);

        $required = (bool) $validated['require_discord_verification'];

        SettingService::setDiscordVerificationRequired($required);

        return redirect()->route('admin.settings')->with([
            'alert-message' => $required ? 'Discord verification is now required.' : 'Discord verification is now optional.',
            'alert-type' => 'success',
        ]);
    }

    public function updateHomepage(Request $request): RedirectResponse
    {
        $this->authorize('view-diagnostic-info');

        $validated = $request->validate([
            'home_headline' => ['required', 'string', 'max:160'],
            'home_tagline' => ['required', 'string', 'max:240'],
            'home_about' => ['nullable', 'string', 'max:800'],
            'home_highlights' => ['array'],
            'home_highlights.*' => ['nullable', 'string', 'max:140'],
        ]);

        SettingService::setHomepageHeadline($validated['home_headline']);
        SettingService::setHomepageTagline($validated['home_tagline']);
        SettingService::setHomepageAbout($validated['home_about'] ?? '');

        $highlights = collect($validated['home_highlights'] ?? [])
            ->map(fn ($item) => (string) $item)
            ->all();

        SettingService::setHomepageHighlights($highlights);

        return redirect()->route('admin.settings')->with([
            'alert-message' => 'Homepage content updated.',
            'alert-type' => 'success',
        ]);
    }

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
