<?php

namespace App\Jobs;

use App\Enums\SpyAssignmentStatus;
use App\Models\SpyAssignment;
use App\Models\SpyAssignmentMessageLog;
use App\Models\SpyRound;
use App\Services\PWMessageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Sends in-game spy assignment notifications to aggressors.
 */
class SendSpyAssignmentsNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;

    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public readonly int $spyRoundId,
        public readonly string $message,
        public readonly array $options = [],
    ) {}

    public function handle(PWMessageService $messageService): void
    {
        /** @var SpyRound|null $round */
        $round = SpyRound::query()
            ->with([
                'campaign',
                'assignments.attacker',
                'assignments.defender',
            ])
            ->find($this->spyRoundId);

        if (! $round) {
            Log::warning('Spy round missing during notification send', ['round_id' => $this->spyRoundId]);

            return;
        }

        $grouped = $round->assignments->groupBy('attacker_nation_id');
        $subject = sprintf(
            'Spy Orders: %s R%d %s',
            $round->campaign?->name ?? 'Campaign',
            $round->round_number,
            Str::headline($round->op_type?->value ?? '')
        );

        foreach ($grouped as $attackerId => $assignments) {
            $hash = sha1($this->message.$attackerId.$round->id.implode('-', $assignments->pluck('id')->all()));

            $alreadySent = SpyAssignmentMessageLog::query()
                ->where('spy_round_id', $round->id)
                ->where('attacker_nation_id', $attackerId)
                ->where('message_hash', $hash)
                ->exists();

            if ($alreadySent) {
                continue;
            }

            $body = $this->buildMessageBody($round, $assignments->all());

            $sent = $messageService->sendMessage((int) $attackerId, $subject, $body);

            SpyAssignmentMessageLog::query()->create([
                'spy_round_id' => $round->id,
                'attacker_nation_id' => $attackerId,
                'message_hash' => $hash,
                'sent_at' => now(),
            ]);

            if ($sent) {
                SpyAssignment::query()
                    ->whereIn('id', $assignments->pluck('id'))
                    ->update(['status' => SpyAssignmentStatus::SENT->value]);
            }
        }
    }

    /**
     * @param  array<int, SpyAssignment>  $assignments
     */
    protected function buildMessageBody(SpyRound $round, array $assignments): string
    {
        $lines = [$this->message, '', '[b]Assignments[/b]:'];

        foreach ($assignments as $assignment) {
            $defender = $assignment->defender;
            $lines[] = sprintf(
                '- %s (%s) • %s • Safety %d • %s%% odds [link=https://politicsandwar.com/nation/espionage/eid=%d]Espionage Page[/link]',
                $defender?->leader_name ?? 'Unknown',
                $defender?->nation_name ?? 'Target',
                Str::headline($assignment->op_type?->value ?? ''),
                $assignment->safety_level,
                number_format((float) $assignment->calculated_odds, 2),
                $defender?->id ?? 0
            );
        }

        return implode("\n", array_filter($lines));
    }
}
