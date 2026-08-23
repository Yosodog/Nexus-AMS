<?php

namespace App\Services\AllianceSetup;

use App\DataTransferObjects\AllianceSetupState;
use App\Enums\AllianceSetupStatus;
use App\Enums\AllianceSetupStep;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

final class AllianceSetupStateStore
{
    public const SETTING_KEY = 'alliance_setup_v1';

    public function read(): AllianceSetupState
    {
        $value = Setting::query()->where('key', self::SETTING_KEY)->value('value');

        return is_string($value) ? AllianceSetupState::fromJson($value) : AllianceSetupState::legacyCompleted();
    }

    public function initializeFresh(): bool
    {
        return DB::table('settings')->insertOrIgnore([
            'key' => self::SETTING_KEY,
            'value' => AllianceSetupState::fresh()->toJson(),
            'created_at' => now(),
            'updated_at' => now(),
        ]) === 1;
    }

    public function acknowledgeIntro(int $actorId, bool $start): AllianceSetupState
    {
        return $this->mutate(function (AllianceSetupState $state) use ($actorId, $start): AllianceSetupState {
            $now = now()->toIso8601String();

            return new AllianceSetupState(
                AllianceSetupState::VERSION,
                $start ? AllianceSetupStatus::InProgress : $state->status,
                $state->currentStep,
                $state->introAcknowledgedAt ?? $now,
                $state->introAcknowledgedBy ?? $actorId,
                $start ? ($state->startedAt ?? $now) : $state->startedAt,
                $start ? ($state->startedBy ?? $actorId) : $state->startedBy,
                $state->completedAt,
                $state->completedBy,
            );
        });
    }

    public function start(int $actorId): AllianceSetupState
    {
        return $this->mutate(function (AllianceSetupState $state) use ($actorId): AllianceSetupState {
            $now = now()->toIso8601String();

            return new AllianceSetupState(
                AllianceSetupState::VERSION,
                AllianceSetupStatus::InProgress,
                AllianceSetupStep::Platform,
                $state->introAcknowledgedAt ?? $now,
                $state->introAcknowledgedBy ?? $actorId,
                $now,
                $actorId,
            );
        }, createWhenMissing: true);
    }

    public function saveCurrentStep(AllianceSetupStep $step): AllianceSetupState
    {
        return $this->mutate(fn (AllianceSetupState $state): AllianceSetupState => new AllianceSetupState(
            AllianceSetupState::VERSION,
            $state->status === AllianceSetupStatus::NotStarted ? AllianceSetupStatus::InProgress : $state->status,
            $step,
            $state->introAcknowledgedAt,
            $state->introAcknowledgedBy,
            $state->startedAt ?? now()->toIso8601String(),
            $state->startedBy,
            $state->completedAt,
            $state->completedBy,
        ));
    }

    public function complete(int $actorId): AllianceSetupState
    {
        return $this->mutate(fn (AllianceSetupState $state): AllianceSetupState => new AllianceSetupState(
            AllianceSetupState::VERSION,
            AllianceSetupStatus::Completed,
            AllianceSetupStep::Review,
            $state->introAcknowledgedAt,
            $state->introAcknowledgedBy,
            $state->startedAt,
            $state->startedBy,
            now()->toIso8601String(),
            $actorId,
        ));
    }

    public function reset(int $actorId): AllianceSetupState
    {
        return $this->mutate(fn (): AllianceSetupState => new AllianceSetupState(
            AllianceSetupState::VERSION,
            AllianceSetupStatus::InProgress,
            AllianceSetupStep::Platform,
            now()->toIso8601String(),
            $actorId,
            now()->toIso8601String(),
            $actorId,
        ), createWhenMissing: true, acceptCorrupt: true);
    }

    /** @param callable(AllianceSetupState): AllianceSetupState $callback */
    private function mutate(callable $callback, bool $createWhenMissing = false, bool $acceptCorrupt = false): AllianceSetupState
    {
        return DB::transaction(function () use ($callback, $createWhenMissing, $acceptCorrupt): AllianceSetupState {
            $setting = Setting::query()->where('key', self::SETTING_KEY)->lockForUpdate()->first();

            if (! $setting && $createWhenMissing) {
                $this->initializeFresh();
                $setting = Setting::query()->where('key', self::SETTING_KEY)->lockForUpdate()->firstOrFail();
            }

            $state = $setting ? AllianceSetupState::fromJson((string) $setting->value) : AllianceSetupState::legacyCompleted();

            if ($state->corrupt && ! $acceptCorrupt) {
                return $state;
            }

            $updated = $callback($state);

            if ($setting) {
                $setting->forceFill(['value' => $updated->toJson()])->save();
            }

            return $updated;
        }, 3);
    }
}
