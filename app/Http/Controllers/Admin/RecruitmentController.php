<?php

namespace App\Http\Controllers\Admin;

use App\Models\RecruitedNation;
use App\Services\AuditLogger;
use App\Services\PWMessageService;
use App\Services\SettingService;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RecruitmentController
{
    use AuthorizesRequests;

    public function __construct(
        protected PWMessageService $messageService,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * Display the recruitment configuration page.
     */
    public function index(): Factory|View|Application
    {
        $this->authorize('view-recruitment');

        $latestNations = RecruitedNation::with('nation')
            ->orderBy('primary_sent_at', 'desc')
            ->limit(20)
            ->get();

        return view('admin.recruitment.index', [
            'recruitmentEnabled' => SettingService::isRecruitmentEnabled(),
            'followUpEnabled' => SettingService::isRecruitmentFollowUpEnabled(),
            'primarySubject' => SettingService::getRecruitmentPrimarySubject(),
            'primaryMessage' => SettingService::getRecruitmentPrimaryMessage(),
            'followUpSubject' => SettingService::getRecruitmentFollowUpSubject(),
            'followUpMessage' => SettingService::getRecruitmentFollowUpMessage(),
            'userNationId' => auth()->user()?->nation_id,
            'latestNations' => $latestNations,
        ]);
    }

    /**
     * Persist recruitment settings.
     */
    public function update(Request $request): RedirectResponse
    {
        $this->authorize('manage-recruitment');

        $previous = [
            'recruitment_enabled' => SettingService::isRecruitmentEnabled(),
            'follow_up_enabled' => SettingService::isRecruitmentFollowUpEnabled(),
            'primary_subject' => SettingService::getRecruitmentPrimarySubject(),
            'primary_message' => SettingService::getRecruitmentPrimaryMessage(),
            'follow_up_subject' => SettingService::getRecruitmentFollowUpSubject(),
            'follow_up_message' => SettingService::getRecruitmentFollowUpMessage(),
        ];

        $validated = $request->validate([
            'primary_subject' => ['required', 'string', 'max:255'],
            'primary_message' => ['required', 'string'],
            'follow_up_subject' => ['required', 'string', 'max:255'],
            'follow_up_message' => ['required', 'string'],
        ]);

        SettingService::setRecruitmentEnabled($request->boolean('recruitment_enabled'));
        SettingService::setRecruitmentPrimarySubject($validated['primary_subject']);
        SettingService::setRecruitmentPrimaryMessage($validated['primary_message']);

        SettingService::setRecruitmentFollowUpEnabled($request->boolean('follow_up_enabled'));
        SettingService::setRecruitmentFollowUpSubject($validated['follow_up_subject']);
        SettingService::setRecruitmentFollowUpMessage($validated['follow_up_message']);

        $this->auditLogger->success(
            category: 'settings',
            action: 'recruitment_settings_updated',
            context: [
                'changes' => [
                    'recruitment_enabled' => [
                        'from' => $previous['recruitment_enabled'],
                        'to' => $request->boolean('recruitment_enabled'),
                    ],
                    'follow_up_enabled' => [
                        'from' => $previous['follow_up_enabled'],
                        'to' => $request->boolean('follow_up_enabled'),
                    ],
                    'primary_subject' => [
                        'from' => $previous['primary_subject'],
                        'to' => $validated['primary_subject'],
                    ],
                    'primary_message' => [
                        'from' => $previous['primary_message'],
                        'to' => $validated['primary_message'],
                    ],
                    'follow_up_subject' => [
                        'from' => $previous['follow_up_subject'],
                        'to' => $validated['follow_up_subject'],
                    ],
                    'follow_up_message' => [
                        'from' => $previous['follow_up_message'],
                        'to' => $validated['follow_up_message'],
                    ],
                ],
            ],
            message: 'Recruitment settings updated.'
        );

        return redirect()
            ->route('admin.recruitment.index')
            ->with('alert-message', 'Recruitment settings updated.')
            ->with('alert-type', 'success');
    }

    /**
     * Send a test recruitment message to the authenticated admin.
     */
    public function sendTest(Request $request): RedirectResponse
    {
        $this->authorize('manage-recruitment');

        $validated = $request->validate([
            'type' => ['required', 'in:primary,follow_up'],
        ]);

        $user = $request->user();

        if (! $user?->nation_id) {
            return redirect()
                ->route('admin.recruitment.index')
                ->with('alert-message', 'Set your nation ID before sending a test message.')
                ->with('alert-type', 'danger');
        }

        $subject = $validated['type'] === 'primary'
            ? SettingService::getRecruitmentPrimarySubject()
            : SettingService::getRecruitmentFollowUpSubject();

        $message = $validated['type'] === 'primary'
            ? SettingService::getRecruitmentPrimaryMessage()
            : SettingService::getRecruitmentFollowUpMessage();

        $sent = $this->messageService->sendMessage($user->nation_id, $subject, $message);

        if (! $sent) {
            return redirect()
                ->route('admin.recruitment.index')
                ->with('alert-message', 'The Politics & War API rejected the test message.')
                ->with('alert-type', 'danger');
        }

        return redirect()
            ->route('admin.recruitment.index')
            ->with('alert-message', 'Test message sent to your nation inbox.')
            ->with('alert-type', 'success');
    }
}
