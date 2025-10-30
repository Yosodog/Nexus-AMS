<?php

namespace App\Jobs;

use App\Models\RecruitedNation;
use App\Services\RecruitmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendRecruitmentMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $recruitedNationId) {}

    /**
     * Execute the job.
     */
    public function handle(RecruitmentService $service): void
    {
        $record = RecruitedNation::find($this->recruitedNationId);

        if (! $record) {
            Log::warning('Recruitment: follow-up record missing', [
                'record_id' => $this->recruitedNationId,
            ]);

            return;
        }

        $service->sendFollowUp($record);
    }
}
