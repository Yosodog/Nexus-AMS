<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AllianceSetupStep;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AllianceSetupDiscordRequest;
use App\Http\Requests\Admin\AllianceSetupIntroRequest;
use App\Http\Requests\Admin\AllianceSetupRecruitmentRequest;
use App\Models\User;
use App\Services\AllianceSetup\AllianceSetupReadinessService;
use App\Services\AllianceSetup\AllianceSetupService;
use App\Services\AllianceSetup\AllianceSetupStateStore;
use App\Services\RuntimeCapabilities;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class AllianceSetupController extends Controller
{
    public function __construct(
        private readonly AllianceSetupStateStore $states,
        private readonly AllianceSetupReadinessService $readiness,
        private readonly AllianceSetupService $setup,
        private readonly RuntimeCapabilities $capabilities,
    ) {}

    public function index(Request $request): View
    {
        $this->authorizeAccess();

        /** @var User $user */
        $user = $request->user();
        $state = $this->states->read();

        return view('admin.setup.overview', [
            'setupState' => $state,
            'snapshot' => $state->corrupt ? null : $this->readiness->snapshot($user),
        ]);
    }

    public function platform(Request $request): View
    {
        return $this->step($request, AllianceSetupStep::Platform);
    }

    public function security(Request $request): View
    {
        return $this->step($request, AllianceSetupStep::Security);
    }

    public function discord(Request $request): View
    {
        return $this->step($request, AllianceSetupStep::Discord);
    }

    public function recruitment(Request $request): View
    {
        return $this->step($request, AllianceSetupStep::Recruitment);
    }

    public function review(Request $request): View
    {
        return $this->step($request, AllianceSetupStep::Review);
    }

    public function intro(AllianceSetupIntroRequest $request): RedirectResponse
    {
        $this->ensureSupportedRuntime();
        $this->setup->acknowledgeIntro($request->user(), $request->validated('intent') === 'start');

        return $request->validated('intent') === 'start'
            ? redirect()->route('admin.setup.platform')
            : redirect()->route('admin.dashboard')->with('alert-message', 'Setup was deferred. You can resume it at any time.')->with('alert-type', 'info');
    }

    public function start(Request $request): RedirectResponse
    {
        $this->authorizeAccess();
        $this->setup->start($request->user());

        return redirect()->route('admin.setup.platform');
    }

    public function reset(Request $request): RedirectResponse
    {
        $this->authorizeAccess();
        $this->setup->reset($request->user());

        return redirect()->route('admin.setup.platform')->with('alert-message', 'Setup metadata was reset.')->with('alert-type', 'success');
    }

    public function advance(Request $request, AllianceSetupStep $step): RedirectResponse
    {
        $this->authorizeAccess();
        $next = $step->next() ?? AllianceSetupStep::Review;
        $this->setup->continueFrom($next);

        return redirect()->route($next->routeName());
    }

    public function updateDiscord(AllianceSetupDiscordRequest $request): RedirectResponse
    {
        $this->ensureSupportedRuntime();
        $this->setup->saveDiscord(
            $request->user(),
            $request->boolean('configure_now'),
            $request->boolean('verification_required'),
            $request->boolean('private_notifications_enabled'),
        );

        return redirect()->route('admin.setup.recruitment')->with('alert-message', 'Discord preferences saved.')->with('alert-type', 'success');
    }

    public function updateRecruitment(AllianceSetupRecruitmentRequest $request): RedirectResponse
    {
        $this->ensureSupportedRuntime();
        $validated = $request->validated();
        $this->setup->saveRecruitment(
            $request->user(),
            $request->boolean('applications_enabled'),
            isset($validated['approved_position_id']) ? (int) $validated['approved_position_id'] : null,
            $validated['approval_message'] ?? null,
        );

        return redirect()->route('admin.setup.review')->with('alert-message', 'Recruitment preferences saved.')->with('alert-type', 'success');
    }

    public function complete(Request $request): RedirectResponse
    {
        $this->authorizeAccess();
        $this->setup->complete($request->user());

        return redirect()->route('admin.setup.index')->with('alert-message', 'Alliance setup is complete.')->with('alert-type', 'success');
    }

    private function step(Request $request, AllianceSetupStep $step): View
    {
        $this->authorizeAccess();

        /** @var User $user */
        $user = $request->user();

        return view('admin.setup.step', [
            'setupState' => $this->states->read(),
            'currentStep' => $step,
            'snapshot' => $this->readiness->snapshot($user),
        ]);
    }

    private function authorizeAccess(): void
    {
        Gate::authorize('view-diagnostic-info');
        $this->ensureSupportedRuntime();
    }

    private function ensureSupportedRuntime(): void
    {
        abort_unless($this->capabilities->allowsAllianceSetup(), 404);
    }
}
