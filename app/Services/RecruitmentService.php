<?php

namespace App\Services;

use App\Exceptions\PWEntityDoesNotExist;
use App\Exceptions\PWQueryFailedException;
use App\Exceptions\PWRateLimitHitException;
use App\GraphQL\Models\Nation;
use App\Jobs\SendRecruitmentMessage;
use App\Models\RecruitedNation;
use App\Models\RecruitmentMessage;
use App\Models\RecruitmentMessageClick;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class RecruitmentService
{
    public const FOLLOW_UP_DELAY_HOURS = 60;

    private const DEFAULT_BATCH_SIZE = 50;

    public function __construct(
        protected PWMessageService $messageService,
    ) {}

    /**
     * Pull the newest nations and send primary recruitment messages using an A/B testing pattern.
     */
    public function runRecruitmentCycle(): void
    {
        if (! SettingService::isRecruitmentEnabled()) {
            return;
        }

        try {
            $nations = $this->fetchRecentNations();
        } catch (Throwable $e) {
            Log::error('Recruitment: Failed to fetch nations', ['error' => $e->getMessage()]);

            return;
        }

        if ($nations->isEmpty()) {
            return;
        }

        $nationIds = $nations->pluck('id')->filter()->map(fn ($id) => (int) $id)->values();

        if ($nationIds->isEmpty()) {
            return;
        }

        $alreadyRecruited = RecruitedNation::whereIn('nation_id', $nationIds)->pluck('nation_id')->all();

        $activeVariants = RecruitmentMessage::query()
            ->variants()
            ->active()
            ->get();

        $fallbackSubject = SettingService::getRecruitmentPrimarySubject();
        $fallbackMessage = SettingService::getRecruitmentPrimaryMessage();
        $followUpEnabled = SettingService::isRecruitmentFollowUpEnabled();

        foreach ($nations as $nation) {
            if (! isset($nation->id)) {
                continue;
            }

            if (in_array($nation->id, $alreadyRecruited, true)) {
                continue;
            }

            /** @var RecruitmentMessage|null $selectedVariant */
            $selectedVariant = null;

            if ($activeVariants->isNotEmpty()) {
                // A/B testing selection: balance sends by picking the variant with the lowest current_sends.
                $selectedVariant = $activeVariants
                    ->sortBy(fn (RecruitmentMessage $variant) => [$variant->current_sends, $variant->id])
                    ->first();

                $subject = ! empty($selectedVariant->subject)
                    ? $selectedVariant->subject
                    : $fallbackSubject;

                $message = $this->prepareMessageBody($selectedVariant);
            } else {
                $subject = $fallbackSubject;
                $message = $fallbackMessage;
            }

            $sent = $this->messageService->sendMessage(
                $nation->id,
                $subject,
                $message
            );

            if (! $sent) {
                Log::info('Recruitment: primary message not sent, will retry later', [
                    'nation_id' => $nation->id,
                ]);

                continue;
            }

            if ($selectedVariant !== null) {
                $selectedVariant->increment('lifetime_sends');
                $selectedVariant->increment('current_sends');
            }

            $record = RecruitedNation::create([
                'nation_id' => $nation->id,
                'recruitment_message_id' => $selectedVariant?->id,
                'primary_sent_at' => now(),
            ]);

            $alreadyRecruited[] = $nation->id;

            if ($followUpEnabled) {
                $scheduledFor = now()->addHours(self::FOLLOW_UP_DELAY_HOURS);
                $record->update(['follow_up_scheduled_for' => $scheduledFor]);
                SendRecruitmentMessage::dispatch($record->id)->delay($scheduledFor);
            }
        }
    }

    /**
     * Prepare the message body by injecting or appending the tracked apply link.
     */
    public function prepareMessageBody(RecruitmentMessage $variant): string
    {
        $trackingUrl = $variant->tracking_url;
        $body = (string) $variant->message;

        if (str_contains($body, '{apply_link}') || str_contains($body, '{apply_url}')) {
            return str_replace(['{apply_link}', '{apply_url}'], $trackingUrl, $body);
        }

        // If tracking link is not in the template, append it cleanly.
        if (str_contains($body, '</p>')) {
            return $body.'<p><a href="'.e($trackingUrl).'">Apply to join: '.e($trackingUrl).'</a></p>';
        }

        return $body."\n\nApply to join: ".$trackingUrl;
    }

    /**
     * Create a new recruitment message variant and reset the current A/B testing cohort.
     *
     * @param  array{name: string, subject: string, message: string, is_active?: bool}  $data
     */
    public function createMessage(array $data): RecruitmentMessage
    {
        return DB::transaction(function () use ($data): RecruitmentMessage {
            $message = RecruitmentMessage::create([
                'name' => $data['name'],
                'type' => 'variant',
                'subject' => $data['subject'],
                'message' => $data['message'],
                'is_active' => $data['is_active'] ?? true,
                'tracking_key' => Str::lower(Str::random(10)),
            ]);

            // Reset current cohort sends and clicks across all messages so the comparison starts afresh.
            $this->resetCurrentCohort();

            return $message;
        });
    }

    /**
     * Update an existing recruitment message variant.
     *
     * @param  array{name: string, subject: string, message: string, is_active?: bool}  $data
     */
    public function updateMessage(RecruitmentMessage $message, array $data): RecruitmentMessage
    {
        $message->update([
            'name' => $data['name'],
            'subject' => $data['subject'],
            'message' => $data['message'],
            'is_active' => $data['is_active'] ?? $message->is_active,
        ]);

        return $message;
    }

    /**
     * Delete a recruitment message variant.
     */
    public function deleteMessage(RecruitmentMessage $message): void
    {
        $message->delete();
    }

    /**
     * Toggle active state for a recruitment message variant.
     */
    public function toggleActive(RecruitmentMessage $message): bool
    {
        $message->update(['is_active' => ! $message->is_active]);

        return $message->is_active;
    }

    /**
     * Reset current cohort sends and clicks for all messages.
     */
    public function resetCurrentCohort(): void
    {
        RecruitmentMessage::query()->update([
            'current_sends' => 0,
            'current_clicks' => 0,
        ]);

        SettingService::setRecruitmentCurrentCohortStartedAt(now());
    }

    /**
     * Record a click on a recruitment message link, preventing duplicates within the current session.
     */
    public function recordClick(RecruitmentMessage $message, ?string $ip = null, ?string $userAgent = null): bool
    {
        $sessionKey = 'recruitment_click_'.$message->id;

        if (session()->has($sessionKey)) {
            return false;
        }

        $message->increment('lifetime_clicks');
        $message->increment('current_clicks');

        $ipHash = $ip ? hash('sha256', $ip) : null;

        RecruitmentMessageClick::create([
            'recruitment_message_id' => $message->id,
            'ip_hash' => $ipHash,
            'user_agent' => $userAgent ? Str::limit($userAgent, 255, '') : null,
            'created_at' => now(),
        ]);

        session()->put($sessionKey, now()->timestamp);

        return true;
    }

    /**
     * Send the follow-up message when the delay has elapsed.
     *
     * @throws ConnectionException
     * @throws PWQueryFailedException
     * @throws PWRateLimitHitException
     */
    public function sendFollowUp(RecruitedNation $record): void
    {
        if (! SettingService::isRecruitmentEnabled()
            || ! SettingService::isRecruitmentFollowUpEnabled()
            || $record->follow_up_sent_at
        ) {
            return;
        }

        try {
            $nation = NationQueryService::getNationById($record->nation_id);
        } catch (PWEntityDoesNotExist $e) {
            Log::warning('Recruitment: nation missing during follow-up', [
                'nation_id' => $record->nation_id,
            ]);

            return;
        }

        if (! empty($nation->alliance_id)) {
            // Already in an alliance; skip quietly.
            return;
        }

        $subject = SettingService::getRecruitmentFollowUpSubject();
        $message = SettingService::getRecruitmentFollowUpMessage();

        $sent = $this->messageService->sendMessage($record->nation_id, $subject, $message);

        if (! $sent) {
            throw new RuntimeException('Failed to send recruitment follow-up message.');
        }

        $record->update([
            'follow_up_sent_at' => now(),
        ]);
    }

    /**
     * @return Collection<int, Nation>
     *
     * @throws ConnectionException
     * @throws PWQueryFailedException
     */
    protected function fetchRecentNations(): Collection
    {
        $arguments = [
            'orderBy' => [[
                'column' => GraphQLQueryBuilder::literal('DATE'),
                'order' => GraphQLQueryBuilder::literal('DESC'),
            ]],
        ];

        $nations = NationQueryService::getMultipleNations(
            $arguments,
            self::DEFAULT_BATCH_SIZE,
            false,
            false,
            false
        );

        return collect(iterator_to_array($nations));
    }
}
