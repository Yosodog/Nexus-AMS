<?php

namespace App\Services;

use App\Jobs\SendRecruitmentMessage;
use App\Models\RecruitedNation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
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
     * Pull the newest nations and attempt to send the primary recruitment message.
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

        $primarySubject = SettingService::getRecruitmentPrimarySubject();
        $primaryMessage = SettingService::getRecruitmentPrimaryMessage();
        $followUpEnabled = SettingService::isRecruitmentFollowUpEnabled();

        foreach ($nations as $nation) {
            if (! isset($nation->id)) {
                continue;
            }

            if (in_array($nation->id, $alreadyRecruited, true)) {
                continue;
            }

            $sent = $this->messageService->sendMessage(
                $nation->id,
                $primarySubject,
                $primaryMessage
            );

            if (! $sent) {
                Log::info('Recruitment: primary message not sent, will retry later', [
                    'nation_id' => $nation->id,
                ]);

                continue;
            }

            $record = RecruitedNation::create([
                'nation_id' => $nation->id,
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
     * Send the follow-up message when the delay has elapsed.
     *
     *
     * @throws ConnectionException
     * @throws \App\Exceptions\PWQueryFailedException
     * @throws \App\Exceptions\PWRateLimitHitException
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
        } catch (\App\Exceptions\PWEntityDoesNotExist $e) {
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
     * @return Collection<int, \App\GraphQL\Models\Nation>
     *
     * @throws ConnectionException
     * @throws \App\Exceptions\PWQueryFailedException
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
