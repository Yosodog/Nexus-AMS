<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ProcessHeartbeatRole;
use Illuminate\Support\Facades\DB;

final readonly class ProcessHeartbeatRecorder
{
    public function __construct(private RuntimeBuildMetadata $build) {}

    public function record(ProcessHeartbeatRole $role): void
    {
        $observedAt = now();

        DB::transaction(function () use ($observedAt, $role): void {
            DB::table('process_heartbeats')->upsert(
                [[
                    'role' => $role->value,
                    'release_id' => $this->build->releaseId(),
                    'last_seen_at' => $observedAt,
                    'created_at' => $observedAt,
                    'updated_at' => $observedAt,
                ]],
                ['role'],
                ['release_id', 'last_seen_at', 'updated_at'],
            );
        }, attempts: 3);
    }
}
