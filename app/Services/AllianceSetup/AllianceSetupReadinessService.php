<?php

namespace App\Services\AllianceSetup;

use App\Models\Alliance;
use App\Models\Nation;
use App\Models\User;
use App\Services\AllianceMembershipService;
use App\Services\Discord\DiscordConnectionResolutionException;
use App\Services\Discord\DiscordConnectionResolver;
use App\Services\PWHealthService;
use App\Services\RuntimeBuildMetadata;
use App\Services\RuntimeCapabilities;
use App\Services\RuntimeReadinessService;
use App\Services\Settings\ApplicationSettings;
use App\Services\Settings\DiscordSettings;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

final readonly class AllianceSetupReadinessService
{
    public function __construct(
        private RuntimeCapabilities $capabilities,
        private RuntimeBuildMetadata $build,
        private RuntimeReadinessService $runtimeReadiness,
        private AllianceMembershipService $alliances,
        private DiscordConnectionResolver $discordConnections,
        private DiscordSettings $discordSettings,
        private ApplicationSettings $applicationSettings,
    ) {}

    /**
     * @return array{
     *   ready: bool,
     *   required: array<string, array{label: string, passed: bool, detail: string}>,
     *   warnings: array<string, array{label: string, detail: string}>,
     *   context: array<string, mixed>
     * }
     */
    public function snapshot(User $user): array
    {
        $runtime = $this->build->runtime();
        $runtimeReadiness = $this->runtimeReadiness->readiness();
        $allianceId = $this->alliances->getPrimaryAllianceId();
        $alliance = $allianceId > 0 ? Alliance::query()->find($allianceId) : null;
        $nationCount = $allianceId > 0 ? Nation::query()->where('alliance_id', $allianceId)->count() : 0;
        $apiConfigured = $this->configured('services.pw.api_key');
        $mutationConfigured = $this->configured('services.pw.mutation_key');
        $cachedPwStatus = Cache::get(PWHealthService::CACHE_KEY_STATUS);
        $cachedPwStatus = is_bool($cachedPwStatus) ? $cachedPwStatus : null;
        $discord = $this->discordContext();
        $hasTotp = filled($user->two_factor_secret) && filled($user->two_factor_confirmed_at);
        $hasPasskey = $user->passkeys()->exists();
        $applicationsEnabled = $this->applicationSettings->isEnabled();

        $required = [
            'supported_runtime' => $this->check('Supported runtime', $this->capabilities->allowsAllianceSetup(), $runtime->value),
            'runtime_ready' => $this->check('Internal runtime readiness', $runtimeReadiness['ready'], $runtimeReadiness['ready'] ? 'Compatible' : 'Review the runtime readiness checks below.'),
            'primary_alliance' => $this->check('Primary alliance ID', $allianceId > 0, $allianceId > 0 ? (string) $allianceId : 'Not configured'),
            'pw_api_credential' => $this->check('Politics & War API credential', $apiConfigured, $apiConfigured ? 'Configured' : 'Missing'),
            'pw_mutation_credential' => $this->check('Politics & War mutation credential', $mutationConfigured, $mutationConfigured ? 'Configured' : 'Missing'),
            'alliance_record' => $this->check('Primary alliance data', $alliance !== null, $alliance?->name ?? 'No matching alliance record'),
            'alliance_members' => $this->check('Primary alliance nations', $nationCount > 0, $nationCount.' nation'.($nationCount === 1 ? '' : 's').' cached'),
            'pw_cached_health' => $this->check('Cached Politics & War health', $cachedPwStatus !== false, $cachedPwStatus === false ? 'The latest cached check reports an outage.' : 'No explicit outage is cached.'),
        ];

        $warnings = [];
        if ($cachedPwStatus === null) {
            $warnings['pw_health_unknown'] = $this->warning('Politics & War health is unknown', 'No cached health result is available yet. This does not block setup.');
        }
        if ($this->isStale($alliance?->updated_at, 25)) {
            $warnings['alliance_data_stale'] = $this->warning('Alliance data may be stale', 'The primary alliance record is more than 25 hours old.');
        }
        $latestNationUpdate = $allianceId > 0 ? Nation::query()->where('alliance_id', $allianceId)->max('updated_at') : null;
        if ($nationCount > 0 && $this->isStale($latestNationUpdate, 50)) {
            $warnings['nation_data_stale'] = $this->warning('Nation data may be stale', 'The newest primary-alliance nation record is more than 50 hours old.');
        }
        if (! $hasTotp) {
            $warnings['admin_totp_missing'] = $this->warning('TOTP is not configured', 'Add an authenticator app as a recovery-friendly sign-in factor.');
        }
        if (! $hasPasskey) {
            $warnings['admin_passkey_missing'] = $this->warning('No passkey is registered', 'A passkey provides a phishing-resistant sign-in option.');
        }
        if (! $discord['connected']) {
            $warnings['discord_not_connected'] = $this->warning('Discord is not connected', 'Discord preferences can remain disabled and be configured later.');
        }
        if (! $applicationsEnabled) {
            $warnings['applications_disabled'] = $this->warning('Applications are disabled', 'Recruitment intake can remain disabled until the alliance is ready.');
        }

        return [
            'ready' => collect($required)->every(fn (array $check): bool => $check['passed']),
            'required' => $required,
            'warnings' => $warnings,
            'context' => [
                'runtime' => $runtime->value,
                'managed' => $this->build->managed(),
                'runtime_checks' => $runtimeReadiness['checks'],
                'alliance_id' => $allianceId,
                'alliance_name' => $alliance?->name,
                'nation_count' => $nationCount,
                'alliance_updated_at' => $alliance?->updated_at?->toIso8601String(),
                'nation_updated_at' => $latestNationUpdate,
                'api_configured' => $apiConfigured,
                'mutation_configured' => $mutationConfigured,
                'pw_health' => $cachedPwStatus,
                'pw_checked_at' => Cache::get(PWHealthService::CACHE_KEY_CHECKED_AT),
                'has_totp' => $hasTotp,
                'has_passkey' => $hasPasskey,
                'discord' => $discord,
                'discord_verification_required' => $this->discordSettings->isVerificationRequired(),
                'discord_private_notifications' => $this->discordSettings->arePrivateNotificationsEnabled(),
                'applications_enabled' => $applicationsEnabled,
                'approved_position_id' => $this->applicationSettings->getApprovedPositionId(),
                'approval_message' => $this->applicationSettings->getApprovalMessageTemplate(),
            ],
        ];
    }

    /** @return array{label: string, passed: bool, detail: string} */
    private function check(string $label, bool $passed, string $detail): array
    {
        return compact('label', 'passed', 'detail');
    }

    /** @return array{label: string, detail: string} */
    private function warning(string $label, string $detail): array
    {
        return compact('label', 'detail');
    }

    private function configured(string $key): bool
    {
        $value = config($key);

        return is_string($value) && trim($value) !== '';
    }

    /** @return array{connected: bool, source: string|null, mode: string|null, guild_id: string|null} */
    private function discordContext(): array
    {
        try {
            $connection = $this->discordConnections->resolveForQueueProducer();

            return [
                'connected' => true,
                'source' => $connection->persisted ? 'accepted connection' : 'configured fallback',
                'mode' => $connection->mode->value,
                'guild_id' => $connection->guildId,
            ];
        } catch (DiscordConnectionResolutionException) {
            return ['connected' => false, 'source' => null, 'mode' => null, 'guild_id' => null];
        }
    }

    private function isStale(mixed $timestamp, int $hours): bool
    {
        if ($timestamp === null) {
            return false;
        }

        if ($timestamp instanceof CarbonInterface) {
            return $timestamp->isBefore(now()->subHours($hours));
        }

        return CarbonImmutable::parse((string) $timestamp)->isBefore(now()->subHours($hours));
    }
}
