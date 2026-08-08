<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\NexusRuntime;
use Illuminate\Support\Str;

final readonly class RuntimeBuildMetadata
{
    public const ENDPOINT_CONTRACT = 1;

    public const RUNTIME_CONTRACT = 1;

    public const TENANT_SCHEMA = 41;

    public const WORLD_VIEW_MIN = 3;

    public const WORLD_VIEW_MAX = 4;

    private const RELEASE_PATTERN = '/\A[a-zA-Z0-9][a-zA-Z0-9._:@-]{0,63}\z/D';

    private const VERSION_PATTERN = '/\A[a-zA-Z0-9][a-zA-Z0-9.+_-]{0,63}\z/D';

    private const IMAGE_DIGEST_PATTERN = '/\Asha256:[a-f0-9]{64}\z/D';

    private const COMMIT_PATTERN = '/\A[a-f0-9]{7,64}\z/D';

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

    public function managed(): bool
    {
        return filter_var(
            config('nexus.managed'),
            FILTER_VALIDATE_BOOL,
            FILTER_NULL_ON_FAILURE,
        ) ?? false;
    }

    public function tenantId(): ?string
    {
        $tenantId = config('nexus.tenant_id');

        if (! is_string($tenantId)) {
            return null;
        }

        $tenantId = trim($tenantId);

        if (Str::isUlid($tenantId)) {
            return strtoupper($tenantId);
        }

        return Str::isUuid($tenantId) ? strtolower($tenantId) : null;
    }

    public function hasConfiguredTenantId(): bool
    {
        $tenantId = config('nexus.tenant_id');

        return is_string($tenantId) && trim($tenantId) !== '';
    }

    public function configuredRuntimeContract(): int
    {
        $contract = config('nexus.runtime_contract');

        return is_int($contract) ? $contract : 0;
    }

    public function configuredWorldViewContract(): int
    {
        $contract = config('nexus.world_view_contract');

        return is_int($contract) && $contract >= 0 ? $contract : 0;
    }

    public function applicationVersion(): string
    {
        return $this->safeBuildValue('application_version', self::VERSION_PATTERN);
    }

    public function imageDigest(): string
    {
        return strtolower($this->safeBuildValue('image_digest', self::IMAGE_DIGEST_PATTERN));
    }

    public function commit(): string
    {
        return strtolower($this->safeBuildValue('commit', self::COMMIT_PATTERN));
    }

    /** @return list<string> */
    public function capabilities(): array
    {
        return match ($this->runtime) {
            NexusRuntime::HostedTenant => ['platform-bootstrap-v1'],
            NexusRuntime::Standalone, NexusRuntime::WorldWriter => [],
        };
    }

    /**
     * @return array{
     *     runtime_contract: int,
     *     runtime_mode: string,
     *     tenant_id: string|null,
     *     release_id: string,
     *     tenant_schema: int,
     *     world_view_contract: int|null,
     *     capabilities: list<string>
     * }
     */
    public function compatibilityHandshake(): array
    {
        return [
            'runtime_contract' => self::RUNTIME_CONTRACT,
            'runtime_mode' => $this->runtime->value,
            'tenant_id' => $this->runtime === NexusRuntime::HostedTenant ? $this->tenantId() : null,
            'release_id' => $this->releaseId(),
            'tenant_schema' => self::TENANT_SCHEMA,
            'world_view_contract' => $this->runtime === NexusRuntime::HostedTenant
                ? $this->configuredWorldViewContract()
                : null,
            'capabilities' => $this->capabilities(),
        ];
    }

    /**
     * @return array{
     *     application: 'nexus-ams',
     *     application_version: string,
     *     image_digest: string,
     *     commit: string,
     *     managed: bool,
     *     world_view_min: int,
     *     world_view_max: int,
     *     runtime_contract: int,
     *     runtime_mode: string,
     *     tenant_id: string|null,
     *     release_id: string,
     *     tenant_schema: int,
     *     world_view_contract: int|null,
     *     capabilities: list<string>
     * }
     */
    public function metadata(): array
    {
        return [
            'application' => 'nexus-ams',
            'application_version' => $this->applicationVersion(),
            'image_digest' => $this->imageDigest(),
            'commit' => $this->commit(),
            'managed' => $this->managed(),
            'world_view_min' => self::WORLD_VIEW_MIN,
            'world_view_max' => self::WORLD_VIEW_MAX,
            ...$this->compatibilityHandshake(),
        ];
    }

    private function safeBuildValue(string $key, string $pattern): string
    {
        $value = config('nexus.build.'.$key);

        return is_string($value) && preg_match($pattern, $value) === 1
            ? $value
            : 'unknown';
    }
}
