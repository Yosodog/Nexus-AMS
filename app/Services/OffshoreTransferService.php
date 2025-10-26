<?php

namespace App\Services;

use App\Events\OffshoreCacheInvalidated;
use App\Exceptions\OffshoreTransferException;
use App\Exceptions\PWQueryFailedException;
use App\GraphQL\GraphQLQueryBuilder;
use App\GraphQL\SelectionSetHelper;
use App\Models\Offshore;
use App\Models\OffshoreTransfer;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Throwable;

class OffshoreTransferService
{
    private readonly int $mainAllianceId;

    public function __construct(
        private readonly OffshoreService $offshoreService,
        ?int $mainAllianceId = null
    ) {
        $resolvedAllianceId = $mainAllianceId !== null && $mainAllianceId > 0
            ? $mainAllianceId
            : (int) env('PW_ALLIANCE_ID', 0);

        $this->mainAllianceId = $resolvedAllianceId;
    }

    /**
     * @param array<string, float> $payload
     */
    public function transfer(
        string $sourceType,
        ?Offshore $source,
        string $destinationType,
        ?Offshore $destination,
        array $payload,
        User $user,
        ?string $note = null
    ): OffshoreTransfer {
        $transfer = new OffshoreTransfer([
            'user_id' => $user->id,
            'source_type' => $sourceType,
            'source_offshore_id' => $source?->id,
            'destination_type' => $destinationType,
            'destination_offshore_id' => $destination?->id,
            'payload' => $payload,
            'status' => OffshoreTransfer::STATUS_PENDING,
            'meta' => array_filter([
                'note' => $note,
            ]),
        ]);

        $transfer->save();

        $noteText = $note ?? $this->buildNote($user, $sourceType, $destinationType, $source, $destination);

        $requiresMainBank = $sourceType === OffshoreTransfer::TYPE_MAIN
            || $destinationType === OffshoreTransfer::TYPE_MAIN
            || ($sourceType === OffshoreTransfer::TYPE_OFFSHORE && $destinationType === OffshoreTransfer::TYPE_OFFSHORE);

        if ($this->mainAllianceId <= 0 && $requiresMainBank) {
            $message = 'Main alliance ID is not configured; cannot complete transfer.';
            $transfer->markFailed($message);

            throw new OffshoreTransferException($message);
        }

        try {
            if ($sourceType === OffshoreTransfer::TYPE_MAIN && $destinationType === OffshoreTransfer::TYPE_OFFSHORE && $destination) {
                $this->sendFromMainToOffshore($destination, $payload, $noteText);
                $this->offshoreService->refreshBalances($destination, true);
                event(new OffshoreCacheInvalidated($destination->id, 'manual-transfer'));
            } elseif ($sourceType === OffshoreTransfer::TYPE_OFFSHORE && $destinationType === OffshoreTransfer::TYPE_MAIN && $source) {
                $this->sendFromOffshoreToMain($source, $payload, $noteText);
                $this->offshoreService->refreshBalances($source, true);
                event(new OffshoreCacheInvalidated($source->id, 'manual-transfer'));
            } elseif ($sourceType === OffshoreTransfer::TYPE_OFFSHORE && $destinationType === OffshoreTransfer::TYPE_OFFSHORE && $source && $destination) {
                // Bridge the transfer through the main bank when moving between two offshores.
                $this->sendFromOffshoreToMain($source, $payload, $noteText . ' (Step 1/2)');
                $this->offshoreService->refreshBalances($source, true);
                event(new OffshoreCacheInvalidated($source->id, 'manual-transfer'));

                $this->sendFromMainToOffshore($destination, $payload, $noteText . ' (Step 2/2)');
                $this->offshoreService->refreshBalances($destination, true);
                event(new OffshoreCacheInvalidated($destination->id, 'manual-transfer'));
            } else {
                throw new OffshoreTransferException('Unsupported transfer configuration.');
            }
        } catch (OffshoreTransferException $exception) {
            $transfer->markFailed($exception->getMessage());
            throw $exception;
        } catch (Throwable $exception) {
            $transfer->markFailed($exception->getMessage());

            throw new OffshoreTransferException('Unexpected error: ' . $exception->getMessage(), previous: $exception);
        }

        $transfer->markCompleted('Transfer completed successfully.');

        return $transfer->refresh();
    }

    /**
     * Send funds from the main alliance bank to an offshore partner.
     *
     * @param array<string, float> $payload
     */
    protected function sendFromMainToOffshore(Offshore $offshore, array $payload, string $note): void
    {
        $this->executeTransfer(
            fn(GraphQLQueryBuilder $builder) => $builder
                ->addArgument('receiver', $offshore->alliance_id)
                ->addArgument('receiver_type', 2)
                ->addArgument('note', $note),
            $payload,
            [],
            [
                'direction' => 'main_to_offshore',
                'offshore_id' => $offshore->id,
            ]
        );
    }

    /**
     * Request a withdrawal from an offshore into the main alliance bank.
     *
     * @param array<string, float> $payload
     */
    protected function sendFromOffshoreToMain(Offshore $offshore, array $payload, string $note): void
    {
        $this->executeTransfer(
            fn(GraphQLQueryBuilder $builder) => $builder
                ->addArgument('receiver', $this->mainAllianceId)
                ->addArgument('receiver_type', 2)
                ->addArgument('note', $note),
            $payload,
            [
                'apiKey' => $offshore->api_key_decrypted,
                'mutationKey' => $offshore->mutation_key_decrypted,
            ],
            [
                'direction' => 'offshore_to_main',
                'offshore_id' => $offshore->id,
            ]
        );
    }

    /**
     * @param array<string, float> $payload
     * @param array<string, mixed> $context
     */
    protected function executeTransfer(
        callable $builderCallback,
        array $payload,
        array $parameters,
        array $context
    ): void {
        /** @var QueryService $client */
        $client = App::make(QueryService::class, $parameters);

        $builder = (new GraphQLQueryBuilder())
            ->setRootField('bankWithdraw')
            ->setMutation()
            ->addFields(SelectionSetHelper::bankRecordSet());

        $builderCallback($builder);

        foreach ($payload as $resource => $amount) {
            if ($amount <= 0) {
                continue;
            }

            $builder->addArgument($resource, $amount);
        }

        try {
            // Headers are required for bank mutations; QueryService handles authentication internally.
            $client->sendQuery($builder, headers: true);
        } catch (ConnectionException $exception) {
            Log::error('Manual offshore transfer failed due to connection error', array_merge($context, [
                'message' => $exception->getMessage(),
            ]));

            throw new OffshoreTransferException('Connection error while executing the transfer.', previous: $exception);
        } catch (PWQueryFailedException $exception) {
            Log::error('Manual offshore transfer was rejected by the PW API', array_merge($context, [
                'message' => $exception->getMessage(),
            ]));

            throw new OffshoreTransferException($exception->getMessage(), previous: $exception);
        } catch (Throwable $exception) {
            Log::error('Unexpected error during manual offshore transfer', array_merge($context, [
                'message' => $exception->getMessage(),
            ]));

            throw new OffshoreTransferException('Unexpected error: ' . $exception->getMessage(), previous: $exception);
        }
    }

    protected function buildNote(
        User $user,
        string $sourceType,
        string $destinationType,
        ?Offshore $source,
        ?Offshore $destination
    ): string {
        $sourceLabel = $this->resolveLabel($sourceType, $source);
        $destinationLabel = $this->resolveLabel($destinationType, $destination);

        return sprintf(
            'Manual transfer from %s to %s by %s (ID #%d)',
            $sourceLabel,
            $destinationLabel,
            $user->name,
            $user->id
        );
    }

    protected function resolveLabel(string $type, ?Offshore $offshore): string
    {
        if ($type === OffshoreTransfer::TYPE_MAIN) {
            return 'Main Bank';
        }

        return $offshore?->name ?? 'Offshore';
    }
}
