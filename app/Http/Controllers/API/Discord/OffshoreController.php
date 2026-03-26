<?php

namespace App\Http\Controllers\API\Discord;

use App\Events\OffshoreCacheInvalidated;
use App\Exceptions\OffshoreTransferException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Discord\DiscordOffshoreSweepRequest;
use App\Models\DiscordAccount;
use App\Models\OffshoreTransfer;
use App\Services\AuditLogger;
use App\Services\MainBankService;
use App\Services\OffshoreService;
use App\Services\OffshoreTransferService;
use App\Services\PWHelperService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class OffshoreController extends Controller
{
    public function __construct(
        private readonly OffshoreService $offshoreService,
        private readonly OffshoreTransferService $transferService,
        private readonly MainBankService $mainBankService,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function sweepPrimary(DiscordOffshoreSweepRequest $request): JsonResponse
    {
        $moderatorDiscordId = $request->string('moderator_discord_id')->toString();
        $moderator = DiscordAccount::query()
            ->where('discord_id', $moderatorDiscordId)
            ->whereNull('unlinked_at')
            ->latest('linked_at')
            ->first()?->user;

        if (! $moderator) {
            $this->auditLogger->denied(
                category: 'offshore',
                action: 'discord_main_bank_sweep',
                context: [
                    'data' => [
                        'moderator_discord_id' => $moderatorDiscordId,
                    ],
                ],
                message: 'Discord offshore sweep denied because the moderator is not linked.'
            );

            return response()->json([
                'error' => 'moderator_not_found',
                'message' => 'Moderator account is not linked to Nexus.',
            ], 403);
        }

        if (! Gate::forUser($moderator)->allows('manage-offshores')) {
            $this->auditLogger->denied(
                category: 'offshore',
                action: 'discord_main_bank_sweep',
                context: [
                    'data' => [
                        'moderator_discord_id' => $moderatorDiscordId,
                    ],
                ],
                message: 'Discord offshore sweep denied because the moderator lacks permission.',
                actorOverride: [
                    'type' => 'user',
                    'id' => $moderator->id,
                    'name' => $moderator->name,
                ],
            );

            return response()->json([
                'error' => 'forbidden',
                'message' => 'You do not have permission to manage offshores.',
            ], 403);
        }

        $offshore = $this->offshoreService->primary();

        if (! $offshore) {
            return response()->json([
                'error' => 'offshore_not_configured',
                'message' => 'No enabled offshore is configured for sweeps.',
            ], 422);
        }

        $balances = $this->mainBankService->refreshBalances();
        $payload = collect(PWHelperService::resources())
            ->mapWithKeys(fn (string $resource) => [
                $resource => (float) ($balances[$resource] ?? 0),
            ])
            ->filter(fn (float $amount) => $amount > 0)
            ->all();

        if ($payload === []) {
            return response()->json([
                'swept' => false,
                'message' => 'Main bank is already empty.',
                'offshore' => [
                    'id' => $offshore->id,
                    'name' => $offshore->name,
                    'alliance_id' => $offshore->alliance_id,
                ],
            ]);
        }

        try {
            $transfer = $this->transferService->transfer(
                OffshoreTransfer::TYPE_MAIN,
                null,
                OffshoreTransfer::TYPE_OFFSHORE,
                $offshore,
                $payload,
                $moderator,
                $request->string('note')->trim()->toString() ?: sprintf('Discord main bank sweep into %s', $offshore->name)
            );
        } catch (OffshoreTransferException $exception) {
            $this->auditLogger->failure(
                category: 'offshore',
                action: 'discord_main_bank_sweep',
                subject: $offshore,
                context: [
                    'data' => [
                        'moderator_discord_id' => $moderatorDiscordId,
                        'resources' => $payload,
                    ],
                ],
                message: 'Discord offshore sweep failed: '.$exception->getMessage(),
                actorOverride: [
                    'type' => 'user',
                    'id' => $moderator->id,
                    'name' => $moderator->name,
                ],
            );

            return response()->json([
                'error' => 'transfer_failed',
                'message' => $exception->getMessage(),
            ], 422);
        }

        $this->mainBankService->refreshBalances();
        $this->offshoreService->refreshBalances($offshore, true);
        event(new OffshoreCacheInvalidated($offshore->id, 'discord-main-bank-sweep'));

        $this->auditLogger->recordAfterCommit(
            category: 'offshore',
            action: 'discord_main_bank_sweep',
            outcome: 'success',
            severity: 'warning',
            subject: $transfer,
            context: [
                'related' => [
                    ['type' => 'Offshore', 'id' => (string) $offshore->id, 'role' => 'destination'],
                ],
                'data' => [
                    'moderator_discord_id' => $moderatorDiscordId,
                    'resources' => $payload,
                ],
            ],
            message: 'Discord main bank sweep dispatched.',
            actorOverride: [
                'type' => 'user',
                'id' => $moderator->id,
                'name' => $moderator->name,
            ],
        );

        return response()->json([
            'swept' => true,
            'message' => sprintf('Main bank swept into %s.', $offshore->name),
            'offshore' => [
                'id' => $offshore->id,
                'name' => $offshore->name,
                'alliance_id' => $offshore->alliance_id,
            ],
            'transfer' => [
                'id' => $transfer->id,
                'status' => $transfer->status,
                'message' => $transfer->message,
                'payload' => $transfer->payload,
                'completed_at' => optional($transfer->completed_at)->toIso8601String(),
            ],
        ]);
    }
}
