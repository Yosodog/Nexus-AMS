<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\NexusRuntime;

final readonly class RuntimeBuildMetadata
{
    private const RELEASE_PATTERN = '/\A[a-zA-Z0-9][a-zA-Z0-9._:@-]{0,63}\z/D';

    public function __construct(private NexusRuntime $runtime) {}

    public function runtime(): NexusRuntime
    {
        return $this->runtime;
    }

    public function releaseId(): string
    {
        $releaseId = config('nexus.release_id');

        return is_string($releaseId)
            && preg_match(self::RELEASE_PATTERN, $releaseId) === 1
                ? $releaseId
                : 'unknown';
    }

    public function hasConfiguredReleaseId(): bool
    {
        return ! in_array(strtolower($this->releaseId()), ['local', 'unknown'], true);
    }
}
