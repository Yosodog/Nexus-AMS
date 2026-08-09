<?php

namespace App\Services\Discord;

use App\Events\OffshoreCacheInvalidated;
use App\Exceptions\OffshoreTransferException;
use App\Exceptions\OffshoreTransferReconciliationException;
use App\Models\Offshore;
use App\Models\OffshoreTransfer;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\MainBankService;
use App\Services\OffshoreService;
use App\Services\OffshoreTransferService;
use App\Services\PWHelperService;
use Illuminate\Support\Facades\Gate;

final readonly class DiscordOffshoreSweepIntentService
{
    public const INTENT_ACTION = 'offshore.sweep-primary';

    public function __construct(
        private OffshoreService $offshores,
        private OffshoreTransferService $transfers,
        private MainBankService $mainBank,
        private AuditLogger $audit,
    ) {}

    /** @return array<string, mixed> */
    public function preview(User $moderator, ?string $note): array
    {
        $this->authorize($moderator);
        $offshore = $this->primaryOffshore();
        $resources = $this->currentResources();

        return [
            'sweep_required' => $resources !== [],
            'resource_version' => $this->resourceVersion($offshore, $resources),
            'transfer_request_id' => (string) str()->uuid(),
            'note' => $this->note($note, $offshore),
            'offshore' => $this->offshorePayload($offshore),
            'resources' => $resources,
            'warnings' => $resources === []
                ? []
                : ['Balances will be refreshed and must still match this preview when you confirm.'],
        ];
    }

    /** @param array<string, mixed> $intentPayload */
    public function confirm(User $moderator, array $intentPayload): OffshoreTransfer
    {
        $this->authorize($moderator);
        $offshore = $this->primaryOffshore();
        $resources = $this->currentResources();
        $expectedOffshoreId = $intentPayload['offshore_id'] ?? null;
        $expectedVersion = $intentPayload['resource_version'] ?? null;
        $requestId = $intentPayload['transfer_request_id'] ?? null;
        $note = $intentPayload['note'] ?? null;
        if (! is_int($expectedOffshoreId)
            || $expectedOffshoreId !== (int) $offshore->getKey()
            || ! is_string($expectedVersion)
            || ! hash_equals($this->resourceVersion($offshore, $resources), $expectedVersion)
            || $resources === []
            || ! is_string($requestId)
            || preg_match('/^[0-9a-f-]{36}$/i', $requestId) !== 1
            || ! is_string($note)
            || trim($note) === ''
            || mb_strlen($note) > 255) {
            throw new DiscordOffshoreSweepException(
                'offshore_sweep_stale',
                'Main-bank balances or the primary offshore changed after this preview.',
                409,
                'Run /sweepbank again to review fresh balances.',
            );
        }

        try {
            $transfer = $this->transfers->transfer(
                OffshoreTransfer::TYPE_MAIN,
                null,
                OffshoreTransfer::TYPE_OFFSHORE,
                $offshore,
                $resources,
                $moderator,
                $note,
                $requestId,
            );
        } catch (OffshoreTransferReconciliationException $exception) {
            return $exception->transfer;
        } catch (OffshoreTransferException $exception) {
            $transfer = OffshoreTransfer::query()->where('idempotency_key', $requestId)->first();
            if ($transfer?->status === OffshoreTransfer::STATUS_RECONCILIATION_REQUIRED) {
                return $transfer;
            }

            throw new DiscordOffshoreSweepException(
                'offshore_sweep_failed',
                $exception->getMessage(),
                422,
                'Review the error in Nexus before retrying the sweep.',
            );
        }

        $this->mainBank->refreshBalances();
        $this->offshores->refreshBalances($offshore, true);
        event(new OffshoreCacheInvalidated($offshore->id, 'discord-main-bank-sweep-intent'));
        $this->audit->recordAfterCommit(
            category: 'offshore',
            action: 'discord_main_bank_sweep_confirmed',
            outcome: 'success',
            severity: 'warning',
            subject: $transfer,
            context: ['data' => [
                'offshore_id' => (int) $offshore->getKey(),
                'resource_version' => $expectedVersion,
                'resource_count' => count($resources),
            ]],
            message: 'Confirmed Discord main-bank sweep dispatched.',
            actorOverride: [
                'type' => 'user',
                'id' => (int) $moderator->getKey(),
                'name' => $moderator->name,
            ],
        );

        return $transfer;
    }

    private function authorize(User $moderator): void
    {
        if (! $moderator->is_admin || ! Gate::forUser($moderator)->allows('manage-offshores')) {
            $this->audit->denied(
                category: 'offshore',
                action: 'discord_main_bank_sweep_intent',
                message: 'Discord offshore sweep intent denied.',
                actorOverride: [
                    'type' => 'user',
                    'id' => (int) $moderator->getKey(),
                    'name' => $moderator->name,
                ],
            );
            throw new DiscordOffshoreSweepException(
                'forbidden',
                'You do not have permission to manage offshores.',
                403,
            );
        }
    }

    private function primaryOffshore(): Offshore
    {
        $offshore = $this->offshores->primary();
        if (! $offshore) {
            throw new DiscordOffshoreSweepException(
                'offshore_not_configured',
                'No enabled offshore is configured for sweeps.',
                422,
                'Configure a primary offshore in Nexus before retrying.',
            );
        }

        return $offshore;
    }

    /** @return array<string, float> */
    private function currentResources(): array
    {
        $balances = $this->mainBank->refreshBalances();

        return collect(PWHelperService::resources())
            ->mapWithKeys(fn (string $resource): array => [$resource => (float) ($balances[$resource] ?? 0)])
            ->filter(fn (float $amount): bool => $amount > 0)
            ->sortKeys()
            ->all();
    }

    /** @param array<string, float> $resources */
    private function resourceVersion(Offshore $offshore, array $resources): string
    {
        $normalized = collect($resources)
            ->map(fn (float $amount): string => number_format($amount, 8, '.', ''))
            ->sortKeys()
            ->all();

        return hash('sha256', json_encode([
            'offshore_id' => (int) $offshore->getKey(),
            'alliance_id' => (int) $offshore->alliance_id,
            'resources' => $normalized,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function note(?string $note, Offshore $offshore): string
    {
        $note = trim((string) $note);

        return $note !== '' ? $note : sprintf('Discord main bank sweep into %s', $offshore->name);
    }

    /** @return array{id: int, name: string, alliance_id: int} */
    private function offshorePayload(Offshore $offshore): array
    {
        return [
            'id' => (int) $offshore->getKey(),
            'name' => (string) $offshore->name,
            'alliance_id' => (int) $offshore->alliance_id,
        ];
    }
}
