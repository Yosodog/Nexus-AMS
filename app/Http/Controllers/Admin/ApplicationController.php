<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ApplicationStatus;
use App\Exceptions\ApplicationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ApplicationSettingsRequest;
use App\Models\Application;
use App\Services\ApplicationService;
use App\Services\SettingService;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application as LaravelApplication;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ApplicationController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly ApplicationService $applicationService) {}

    public function index(): Factory|View|LaravelApplication
    {
        $this->authorize('view-applications');

        $openApplications = Application::query()
            ->where('status', ApplicationStatus::Pending->value)
            ->orderBy('created_at')
            ->get();

        $recentApplications = Application::query()
            ->orderByDesc('created_at')
            ->limit(25)
            ->get();

        return view('admin.applications.index', [
            'settings' => [
                'enabled' => SettingService::isApplicationsEnabled(),
                'approved_position_id' => SettingService::getApplicationsApprovedPositionId(),
                'discord_applicant_role_id' => SettingService::getApplicationsDiscordApplicantRoleId(),
                'discord_ia_role_id' => SettingService::getApplicationsDiscordIaRoleId(),
                'discord_member_role_id' => SettingService::getApplicationsDiscordMemberRoleId(),
                'discord_interview_category_id' => SettingService::getApplicationsDiscordInterviewCategoryId(),
                'approval_announcement_channel_id' => SettingService::getApplicationsApprovalAnnouncementChannelId(),
                'approval_message_template' => SettingService::getApplicationsApprovalMessageTemplate(),
            ],
            'canManage' => Gate::allows('manage-applications'),
            'openApplications' => $openApplications,
            'recentApplications' => $recentApplications,
        ]);
    }

    public function updateSettings(ApplicationSettingsRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        SettingService::setApplicationsEnabled($request->boolean('applications_enabled'));
        SettingService::setApplicationsApprovedPositionId((int) $validated['applications_approved_position_id']);
        SettingService::setApplicationsDiscordApplicantRoleId($validated['applications_discord_applicant_role_id'] ?? null);
        SettingService::setApplicationsDiscordIaRoleId($validated['applications_discord_ia_role_id'] ?? null);
        SettingService::setApplicationsDiscordMemberRoleId($validated['applications_discord_member_role_id'] ?? null);
        SettingService::setApplicationsDiscordInterviewCategoryId($validated['applications_discord_interview_category_id'] ?? null);
        SettingService::setApplicationsApprovalAnnouncementChannelId($validated['applications_approval_announcement_channel_id'] ?? null);
        SettingService::setApplicationsApprovalMessageTemplate($validated['applications_approval_message_template']);

        return redirect()
            ->route('admin.applications.index')
            ->with('alert-message', 'Application settings updated.')
            ->with('alert-type', 'success');
    }

    public function show(Application $application): Factory|View|LaravelApplication
    {
        $this->authorize('view-applications');

        $application->load([
            'messages' => fn ($query) => $query->orderBy('sent_at'),
        ]);

        return view('admin.applications.show', [
            'application' => $application,
        ]);
    }

    public function cancel(Application $application, Request $request): RedirectResponse
    {
        try {
            $this->applicationService->cancel($application, $request->user());
        } catch (ApplicationException $e) {
            return redirect()
                ->route('admin.applications.show', $application)
                ->with('alert-message', $e->getMessage())
                ->with('alert-type', 'danger');
        }

        return redirect()
            ->route('admin.applications.show', $application)
            ->with('alert-message', 'Application cancelled.')
            ->with('alert-type', 'success');
    }
}
