<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\RecruitmentSettingsRequest;
use App\Http\Requests\Admin\SendRecruitmentTestRequest;
use App\Http\Requests\Admin\StoreRecruitmentMessageRequest;
use App\Http\Requests\Admin\UpdateRecruitmentMessageRequest;
use App\Models\RecruitedNation;
use App\Models\RecruitmentMessage;
use App\Services\AllianceMembershipService;
use App\Services\AuditLogger;
use App\Services\PWMessageService;
use App\Services\RecruitmentService;
use App\Services\SettingService;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;

class RecruitmentController
{
    use AuthorizesRequests;

    public function __construct(
        protected PWMessageService $messageService,
        private readonly AuditLogger $auditLogger,
        private readonly AllianceMembershipService $membershipService,
        private readonly RecruitmentService $recruitmentService,
    ) {}

    /**
     * Display the recruitment configuration and A/B testing dashboard.
     */
    public function index(): Factory|View|Application
    {
        $this->authorize('view-recruitment');

        $variants = RecruitmentMessage::query()
            ->variants()
            ->orderBy('id')
            ->get();

        $activeVariants = $variants->where('is_active', true);
        $totalCurrentSends = (int) $activeVariants->sum('current_sends');
        $totalCurrentClicks = (int) $activeVariants->sum('current_clicks');
        $currentCohortCtr = $totalCurrentSends > 0
            ? round(($totalCurrentClicks / $totalCurrentSends) * 100, 1)
            : 0.0;

        $totalLifetimeSends = (int) $variants->sum('lifetime_sends');
        $totalLifetimeClicks = (int) $variants->sum('lifetime_clicks');
        $lifetimeCtr = $totalLifetimeSends > 0
            ? round(($totalLifetimeClicks / $totalLifetimeSends) * 100, 1)
            : 0.0;

        $latestNations = RecruitedNation::with(['nation', 'recruitmentMessage'])
            ->orderBy('primary_sent_at', 'desc')
            ->limit(20)
            ->get();

        return view('admin.recruitment.index', [
            'recruitmentEnabled' => SettingService::isRecruitmentEnabled(),
            'followUpEnabled' => SettingService::isRecruitmentFollowUpEnabled(),
            'followUpSubject' => SettingService::getRecruitmentFollowUpSubject(),
            'followUpMessage' => SettingService::getRecruitmentFollowUpMessage(),
            'variants' => $variants,
            'activeVariantsCount' => $activeVariants->count(),
            'totalCurrentSends' => $totalCurrentSends,
            'totalCurrentClicks' => $totalCurrentClicks,
            'currentCohortCtr' => $currentCohortCtr,
            'totalLifetimeSends' => $totalLifetimeSends,
            'totalLifetimeClicks' => $totalLifetimeClicks,
            'lifetimeCtr' => $lifetimeCtr,
            'cohortStartedAt' => SettingService::getRecruitmentCurrentCohortStartedAt(),
            'userNationId' => auth()->user()?->nation_id,
            'latestNations' => $latestNations,
            'primaryAllianceId' => $this->membershipService->getPrimaryAllianceId(),
        ]);
    }

    /**
     * Persist general recruitment and follow-up settings.
     */
    public function update(RecruitmentSettingsRequest $request): RedirectResponse
    {
        $this->authorize('manage-recruitment');

        $previous = [
            'recruitment_enabled' => SettingService::isRecruitmentEnabled(),
            'follow_up_enabled' => SettingService::isRecruitmentFollowUpEnabled(),
            'follow_up_subject' => SettingService::getRecruitmentFollowUpSubject(),
            'follow_up_message' => SettingService::getRecruitmentFollowUpMessage(),
        ];

        $validated = $request->validated();

        SettingService::setRecruitmentEnabled($request->boolean('recruitment_enabled'));
        SettingService::setRecruitmentFollowUpEnabled($request->boolean('follow_up_enabled'));
        SettingService::setRecruitmentFollowUpSubject($validated['follow_up_subject']);
        SettingService::setRecruitmentFollowUpMessage($validated['follow_up_message']);

        if (! empty($validated['primary_subject'])) {
            SettingService::setRecruitmentPrimarySubject($validated['primary_subject']);
        }
        if (! empty($validated['primary_message'])) {
            SettingService::setRecruitmentPrimaryMessage($validated['primary_message']);
        }

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
     * Create a new recruitment message variant and reset the active A/B testing cohort.
     */
    public function storeMessage(StoreRecruitmentMessageRequest $request): RedirectResponse
    {
        $this->authorize('manage-recruitment');

        $message = $this->recruitmentService->createMessage([
            'name' => $request->string('name')->toString(),
            'subject' => $request->string('subject')->toString(),
            'message' => $request->input('message'),
            'is_active' => $request->boolean('is_active', true),
        ]);

        $this->auditLogger->success(
            category: 'recruitment',
            action: 'recruitment_message_created',
            context: [
                'message_id' => $message->id,
                'name' => $message->name,
                'subject' => $message->subject,
                'tracking_key' => $message->tracking_key,
            ],
            message: "Recruitment message variant '{$message->name}' created. A/B testing cohort metrics have been reset."
        );

        return redirect()
            ->route('admin.recruitment.index')
            ->with('alert-message', "Message '{$message->name}' created. Current test cohort metrics have been reset across all variants.")
            ->with('alert-type', 'success');
    }

    /**
     * Update an existing recruitment message variant.
     */
    public function updateMessage(RecruitmentMessage $message, UpdateRecruitmentMessageRequest $request): RedirectResponse
    {
        $this->authorize('manage-recruitment');

        $this->recruitmentService->updateMessage($message, [
            'name' => $request->string('name')->toString(),
            'subject' => $request->string('subject')->toString(),
            'message' => $request->input('message'),
            'is_active' => $request->boolean('is_active', true),
        ]);

        $this->auditLogger->success(
            category: 'recruitment',
            action: 'recruitment_message_updated',
            context: [
                'message_id' => $message->id,
                'name' => $message->name,
            ],
            message: "Recruitment message variant '{$message->name}' updated."
        );

        return redirect()
            ->route('admin.recruitment.index')
            ->with('alert-message', "Message '{$message->name}' updated.")
            ->with('alert-type', 'success');
    }

    /**
     * Delete a recruitment message variant.
     */
    public function deleteMessage(RecruitmentMessage $message): RedirectResponse
    {
        $this->authorize('manage-recruitment');

        $name = $message->name;
        $this->recruitmentService->deleteMessage($message);

        $this->auditLogger->success(
            category: 'recruitment',
            action: 'recruitment_message_deleted',
            context: [
                'message_id' => $message->id,
                'name' => $name,
            ],
            message: "Recruitment message variant '{$name}' deleted."
        );

        return redirect()
            ->route('admin.recruitment.index')
            ->with('alert-message', "Recruitment message '{$name}' removed.")
            ->with('alert-type', 'success');
    }

    /**
     * Toggle active state for a recruitment message variant.
     */
    public function toggleActive(RecruitmentMessage $message): RedirectResponse
    {
        $this->authorize('manage-recruitment');

        $isActive = $this->recruitmentService->toggleActive($message);
        $statusText = $isActive ? 'activated' : 'paused';

        $this->auditLogger->success(
            category: 'recruitment',
            action: 'recruitment_message_status_toggled',
            context: [
                'message_id' => $message->id,
                'is_active' => $isActive,
            ],
            message: "Recruitment message '{$message->name}' {$statusText}."
        );

        return redirect()
            ->route('admin.recruitment.index')
            ->with('alert-message', "Recruitment message '{$message->name}' {$statusText}.")
            ->with('alert-type', 'success');
    }

    /**
     * Manually reset current cohort metrics for all recruitment messages.
     */
    public function resetCohort(): RedirectResponse
    {
        $this->authorize('manage-recruitment');

        $this->recruitmentService->resetCurrentCohort();

        $this->auditLogger->success(
            category: 'recruitment',
            action: 'recruitment_cohort_reset',
            context: [],
            message: 'Recruitment A/B testing cohort metrics manually reset.'
        );

        return redirect()
            ->route('admin.recruitment.index')
            ->with('alert-message', 'Current test cohort sends and clicks have been reset to 0 across all variants.')
            ->with('alert-type', 'success');
    }

    /**
     * Send a test recruitment message to the authenticated admin.
     */
    public function sendTest(SendRecruitmentTestRequest $request): RedirectResponse
    {
        $this->authorize('manage-recruitment');

        $validated = $request->validated();
        $user = $request->user();

        if (! $user?->nation_id) {
            return redirect()
                ->route('admin.recruitment.index')
                ->with('alert-message', 'Set your nation ID before sending a test message.')
                ->with('alert-type', 'danger');
        }

        if ($validated['type'] === 'follow_up') {
            $subject = SettingService::getRecruitmentFollowUpSubject();
            $message = SettingService::getRecruitmentFollowUpMessage();
        } else {
            /** @var RecruitmentMessage|null $variant */
            $variant = null;
            if (! empty($validated['message_id'])) {
                $variant = RecruitmentMessage::query()->find($validated['message_id']);
            }

            if (! $variant) {
                $variant = RecruitmentMessage::query()->variants()->active()->first();
            }

            if ($variant) {
                $subject = ! empty($variant->subject)
                    ? $variant->subject
                    : SettingService::getRecruitmentPrimarySubject();
                $message = $this->recruitmentService->prepareMessageBody($variant);
            } else {
                $subject = SettingService::getRecruitmentPrimarySubject();
                $message = SettingService::getRecruitmentPrimaryMessage();
            }
        }

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
