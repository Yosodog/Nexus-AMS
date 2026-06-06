<?php

namespace App\Services;

use App\Exceptions\PWQueryFailedException;
use App\Models\Offshore;
use App\Models\Transaction;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class OffshoreFulfillmentService
{
    private const LOCK_KEY = 'offshore-withdrawals';

    private const LOCK_TTL_SECONDS = 60;

    private const LOCK_WAIT_SECONDS = 5;

    private int $mainAllianceId;

    public function __construct(
        protected OffshoreService $offshoreService,
        protected AllianceMembershipService $membershipService
    ) {
        $this->mainAllianceId = $this->membershipService->getPrimaryAllianceId();
    }

    /**
     * Attempt to cover a withdrawal shortfall before we hit the main bank.
     *
     * This acquires a coarse lock so only one fulfillment routine can run at a
     * time. Once we hold the lock we calculate the deficit and walk the
     * prioritized offshore list to top up the bank.
     */
    public function coverShortfall(Transaction $transaction): OffshoreFulfillmentResult
    {
        $this->mainAllianceId = $this->membershipService->getPrimaryAllianceId();

        if ($this->mainAllianceId === 0) {
            Log::error('Main alliance id not configured; cannot perform offshore fulfillment', [
                'transaction_id' => $transaction->id,
            ]);

            return new OffshoreFulfillmentResult(
                OffshoreFulfillmentResult::STATUS_FAILED,
                'Missing alliance membership configuration for offshore fulfillment.',
                errors: [
                    [
                        'message' => 'Primary alliance ID is not available in the membership cache.',
                    ],
                ]
            );
        }

        try {
            return Cache::lock(self::LOCK_KEY, self::LOCK_TTL_SECONDS)->block(
                self::LOCK_WAIT_SECONDS,
                fn () => $this->performFulfillment($transaction)
            );
        } catch (LockTimeoutException $exception) {
            Log::warning('Unable to obtain offshore fulfillment lock', [
                'transaction_id' => $transaction->id,
                'message' => $exception->getMessage(),
            ]);

            return new OffshoreFulfillmentResult(
                OffshoreFulfillmentResult::STATUS_TIMEOUT,
                'Timed out acquiring offshore fulfillment lock. Please retry or review manually.',
                errors: [
                    [
                        'message' => 'Failed to acquire offshore fulfillment lock in time.',
                    ],
                ]
            );
        }
    }

    /**
     * Calculate the resource gaps and top them up from a fully planned
     * prioritized offshore withdrawal set.
     */
    protected function performFulfillment(Transaction $transaction): OffshoreFulfillmentResult
    {
        $resources = PWHelperService::resources();
        $required = [];

        foreach ($resources as $resource) {
            $amount = (float) ($transaction->$resource ?? 0);

            if ($amount > 0) {
                $required[$resource] = $amount;
            }
        }

        if (empty($required)) {
            return new OffshoreFulfillmentResult(
                OffshoreFulfillmentResult::STATUS_SKIPPED,
                'No offshore coverage required for this transaction.'
            );
        }

        // Pull a fresh snapshot of the main bank so we can calculate gaps accurately.
        $mainBalances = $this->getMainAllianceBalances();

        if ($mainBalances === null) {
            return new OffshoreFulfillmentResult(
                OffshoreFulfillmentResult::STATUS_FAILED,
                'Unable to read the main alliance balance. Offshore fulfillment was skipped.',
                errors: [
                    [
                        'message' => 'Failed to fetch main alliance balance before fulfillment.',
                    ],
                ],
                initialDeficits: $required
            );
        }

        $deficits = [];

        foreach ($required as $resource => $amount) {
            $current = (float) ($mainBalances[$resource] ?? 0);
            $deficit = max(0, $amount - $current);

            if ($deficit > 0) {
                $deficits[$resource] = $deficit;
            }
        }

        if (empty($deficits)) {
            return new OffshoreFulfillmentResult(
                OffshoreFulfillmentResult::STATUS_SKIPPED,
                'Main bank has sufficient resources; no offshore usage required.'
            );
        }

        /** @var Offshore[] $offshores */
        $offshores = $this->offshoreService->all();
        $initialDeficits = $deficits;
        $plan = $this->buildFulfillmentPlan($transaction, $offshores, $deficits);

        if (! empty($plan['remaining_deficits'])) {
            Log::warning('Unable to fully cover transaction shortfall via offshores', [
                'transaction_id' => $transaction->id,
                'remaining_deficits' => $plan['remaining_deficits'],
            ]);

            return new OffshoreFulfillmentResult(
                OffshoreFulfillmentResult::STATUS_FAILED,
                'Insufficient offshore liquidity to fulfill this transaction automatically.',
                errors: $plan['errors'],
                guardrailBlocks: $plan['guardrail_blocks'],
                remainingDeficits: $plan['remaining_deficits'],
                initialDeficits: $initialDeficits,
                plannedTransfers: $this->markPlannedTransfersAsNotSent($plan['planned_transfers'])
            );
        }

        return $this->executeFulfillmentPlan(
            $transaction,
            $plan['execution_plan'],
            $initialDeficits,
            $plan['planned_transfers'],
            $plan['errors'],
            $plan['guardrail_blocks']
        );
    }

    /**
     * @param  iterable<int, Offshore>  $offshores
     * @param  array<string, float>  $deficits
     * @return array{
     *     execution_plan: array<int, array{offshore: Offshore, resources: array<string, float>}>,
     *     planned_transfers: array<int, array<string, mixed>>,
     *     remaining_deficits: array<string, float>,
     *     errors: array<int, array<string, mixed>>,
     *     guardrail_blocks: array<int, array<string, mixed>>
     * }
     */
    protected function buildFulfillmentPlan(Transaction $transaction, iterable $offshores, array $deficits): array
    {
        $executionPlan = [];
        $plannedTransfers = [];
        $errors = [];
        $guardrailBlocks = [];

        foreach ($offshores as $offshore) {
            if (empty($deficits)) {
                break;
            }

            $credentialError = $this->credentialErrorFor($offshore);

            if ($credentialError) {
                $errors[] = $credentialError;

                continue;
            }

            $balances = $this->offshoreService->refreshBalances($offshore);
            $withdrawalPayload = [];

            // Walk the outstanding deficit list to see what this offshore can cover.
            foreach ($deficits as $resource => $remaining) {
                $balance = (float) Arr::get($balances, $resource, 0);
                $guardrail = $this->offshoreService->guardrailFor($offshore, $resource);
                $minimum = (float) ($guardrail?->minimum_amount ?? 0);
                $available = max(0, $balance - $minimum);

                if ($available <= 0) {
                    if ($minimum > 0 && $balance <= $minimum && $remaining > 0) {
                        // Guardrail prevents us from touching the stash. Record this so the
                        // transaction audit trail shows why we skipped the offshore.
                        $guardrailBlocks[] = [
                            'offshore_id' => $offshore->id,
                            'offshore_name' => $offshore->name,
                            'resource' => $resource,
                            'balance' => $balance,
                            'minimum' => $minimum,
                        ];

                        Log::warning('Offshore guardrail prevented transfer during fulfillment', [
                            'transaction_id' => $transaction->id,
                            'offshore_id' => $offshore->id,
                            'resource' => $resource,
                            'balance' => $balance,
                            'minimum' => $minimum,
                        ]);
                    }

                    continue;
                }

                $amount = min($available, $remaining);

                if ($amount <= 0) {
                    continue;
                }

                $rounded = round($amount, 2);

                if ($rounded <= 0) {
                    continue;
                }

                $withdrawalPayload[$resource] = $rounded;
            }

            if (empty($withdrawalPayload)) {
                continue;
            }

            $executionPlan[] = [
                'offshore' => $offshore,
                'resources' => $withdrawalPayload,
            ];

            $plannedTransfers[] = [
                'offshore_id' => $offshore->id,
                'offshore_name' => $offshore->name,
                'resources' => $withdrawalPayload,
                'status' => 'planned',
            ];

            $deficits = $this->subtractPayloadFromDeficits($deficits, $withdrawalPayload);
        }

        return [
            'execution_plan' => $executionPlan,
            'planned_transfers' => $plannedTransfers,
            'remaining_deficits' => $deficits,
            'errors' => $errors,
            'guardrail_blocks' => $guardrailBlocks,
        ];
    }

    /**
     * @param  array<int, array{offshore: Offshore, resources: array<string, float>}>  $executionPlan
     * @param  array<string, float>  $initialDeficits
     * @param  array<int, array<string, mixed>>  $plannedTransfers
     * @param  array<int, array<string, mixed>>  $errors
     * @param  array<int, array<string, mixed>>  $guardrailBlocks
     */
    protected function executeFulfillmentPlan(
        Transaction $transaction,
        array $executionPlan,
        array $initialDeficits,
        array $plannedTransfers,
        array $errors,
        array $guardrailBlocks
    ): OffshoreFulfillmentResult {
        $remainingDeficits = $initialDeficits;
        $transfers = [];

        foreach ($executionPlan as $index => $step) {
            $offshore = $step['offshore'];
            $withdrawalPayload = $step['resources'];

            try {
                $this->sendOffshoreWithdrawal($offshore, $transaction, $withdrawalPayload);
                $this->offshoreService->refreshBalances($offshore);

                $remainingDeficits = $this->subtractPayloadFromDeficits($remainingDeficits, $withdrawalPayload);

                $transfers[] = [
                    'offshore_id' => $offshore->id,
                    'offshore_name' => $offshore->name,
                    'resources' => $withdrawalPayload,
                ];

                $plannedTransfers[$index]['status'] = 'sent';
                $plannedTransfers[$index]['sent_at'] = now()->toISOString();
            } catch (ConnectionException|PWQueryFailedException $exception) {
                Log::error('Failed to withdraw from offshore during fulfillment', [
                    'transaction_id' => $transaction->id,
                    'offshore_id' => $offshore->id,
                    'message' => $exception->getMessage(),
                ]);

                $errors[] = [
                    'offshore_id' => $offshore->id,
                    'offshore_name' => $offshore->name,
                    'message' => $exception->getMessage(),
                ];

                $plannedTransfers = $this->markExecutionFailure($plannedTransfers, $index, $exception->getMessage());

                return new OffshoreFulfillmentResult(
                    OffshoreFulfillmentResult::STATUS_FAILED,
                    'Offshore fulfillment stopped after a transfer failed. Manual reconciliation is required before retrying.',
                    transfers: $transfers,
                    errors: $errors,
                    guardrailBlocks: $guardrailBlocks,
                    remainingDeficits: $remainingDeficits,
                    initialDeficits: $initialDeficits,
                    plannedTransfers: $plannedTransfers
                );
            } catch (Throwable $exception) {
                Log::error('Unexpected error during offshore fulfillment', [
                    'transaction_id' => $transaction->id,
                    'offshore_id' => $offshore->id,
                    'message' => $exception->getMessage(),
                ]);

                $message = 'Unexpected error: '.$exception->getMessage();

                $errors[] = [
                    'offshore_id' => $offshore->id,
                    'offshore_name' => $offshore->name,
                    'message' => $message,
                ];

                $plannedTransfers = $this->markExecutionFailure($plannedTransfers, $index, $message);

                return new OffshoreFulfillmentResult(
                    OffshoreFulfillmentResult::STATUS_FAILED,
                    'Offshore fulfillment stopped after a transfer failed. Manual reconciliation is required before retrying.',
                    transfers: $transfers,
                    errors: $errors,
                    guardrailBlocks: $guardrailBlocks,
                    remainingDeficits: $remainingDeficits,
                    initialDeficits: $initialDeficits,
                    plannedTransfers: $plannedTransfers
                );
            }
        }

        return new OffshoreFulfillmentResult(
            OffshoreFulfillmentResult::STATUS_FULFILLED,
            'Offshore transfers successfully covered the main bank shortfall.',
            transfers: $transfers,
            errors: $errors,
            guardrailBlocks: $guardrailBlocks,
            remainingDeficits: $remainingDeficits,
            initialDeficits: $initialDeficits,
            plannedTransfers: $plannedTransfers
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function credentialErrorFor(Offshore $offshore): ?array
    {
        $missingApiKey = empty($offshore->api_key_decrypted);
        $missingMutationKey = empty($offshore->mutation_key_decrypted);

        if (! $missingApiKey && ! $missingMutationKey) {
            return null;
        }

        Log::error('Missing offshore credentials for fulfillment plan', [
            'offshore_id' => $offshore->id,
            'missing_api_key' => $missingApiKey,
            'missing_mutation_key' => $missingMutationKey,
        ]);

        return [
            'offshore_id' => $offshore->id,
            'offshore_name' => $offshore->name,
            'message' => 'Offshore credentials are missing or invalid.',
        ];
    }

    /**
     * @param  array<string, float>  $deficits
     * @param  array<string, float>  $payload
     * @return array<string, float>
     */
    protected function subtractPayloadFromDeficits(array $deficits, array $payload): array
    {
        foreach ($payload as $resource => $amount) {
            $deficits[$resource] -= $amount;

            if ($deficits[$resource] <= 0.0001) {
                unset($deficits[$resource]);
            }
        }

        return $deficits;
    }

    /**
     * @param  array<int, array<string, mixed>>  $plannedTransfers
     * @return array<int, array<string, mixed>>
     */
    protected function markPlannedTransfersAsNotSent(array $plannedTransfers): array
    {
        foreach ($plannedTransfers as $index => $plannedTransfer) {
            $plannedTransfers[$index]['status'] = 'not_sent';
            $plannedTransfers[$index]['blocked_reason'] = 'Fulfillment plan could not cover the full transaction shortfall.';
        }

        return $plannedTransfers;
    }

    /**
     * @param  array<int, array<string, mixed>>  $plannedTransfers
     * @return array<int, array<string, mixed>>
     */
    protected function markExecutionFailure(array $plannedTransfers, int $failedIndex, string $message): array
    {
        foreach ($plannedTransfers as $index => $plannedTransfer) {
            if ($index < $failedIndex) {
                continue;
            }

            if ($index === $failedIndex) {
                $plannedTransfers[$index]['status'] = 'review_required';
                $plannedTransfers[$index]['error'] = $message;

                continue;
            }

            $plannedTransfers[$index]['status'] = 'blocked';
            $plannedTransfers[$index]['blocked_reason'] = 'Earlier offshore transfer requires reconciliation.';
        }

        return $plannedTransfers;
    }

    /**
     * Fetch a snapshot of the main alliance bank or null if the query fails.
     */
    protected function getMainAllianceBalances(): ?array
    {
        $builder = (new GraphQLQueryBuilder)
            ->setRootField('alliances')
            ->addArgument('id', $this->mainAllianceId)
            ->addNestedField('data', function (GraphQLQueryBuilder $builder) {
                $builder->addFields(SelectionSetHelper::allianceSet());
            });

        try {
            /** @var QueryService $client */
            $client = App::make(QueryService::class);
            $response = $client->sendQuery($builder);
        } catch (ConnectionException|PWQueryFailedException $exception) {
            Log::error('Failed to read main alliance balances for offshore fulfillment', [
                'alliance_id' => $this->mainAllianceId,
                'message' => $exception->getMessage(),
            ]);

            return null;
        } catch (Throwable $exception) {
            Log::error('Unexpected error fetching main alliance balances', [
                'alliance_id' => $this->mainAllianceId,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }

        $result = (array) ($response->{0} ?? []);
        $resources = PWHelperService::resources();

        return collect($resources)
            ->mapWithKeys(fn (string $resource) => [
                $resource => (float) Arr::get($result, $resource, 0),
            ])
            ->all();
    }

    /**
     * @param  array<string, float>  $payload
     *
     * @throws ConnectionException
     * @throws PWQueryFailedException
     */
    protected function sendOffshoreWithdrawal(Offshore $offshore, Transaction $transaction, array $payload): void
    {
        $apiKey = $offshore->api_key_decrypted;
        $mutationKey = $offshore->mutation_key_decrypted;

        if (! $apiKey || ! $mutationKey) {
            Log::error('Missing offshore credentials for fulfillment withdrawal', [
                'transaction_id' => $transaction->id,
                'offshore_id' => $offshore->id,
                'missing_api_key' => empty($apiKey),
                'missing_mutation_key' => empty($mutationKey),
            ]);

            throw new PWQueryFailedException('Offshore credentials are missing or invalid.');
        }

        $parameters = [
            'apiKey' => $apiKey,
            'mutationKey' => $mutationKey,
        ];

        /** @var QueryService $client */
        $client = App::make(QueryService::class, $parameters);

        $builder = (new GraphQLQueryBuilder)
            ->setRootField('bankWithdraw')
            ->setMutation()
            ->addArgument('receiver', $this->mainAllianceId)
            ->addArgument('receiver_type', 2)
            ->addArgument('note', sprintf('Offshore fulfillment for transaction %d', $transaction->id))
            ->addFields(SelectionSetHelper::bankRecordSet());

        foreach ($payload as $resource => $amount) {
            $builder->addArgument($resource, $amount);
        }

        // We do not log any request context here to avoid leaking credentials.
        $client->sendQuery($builder, headers: true);
    }
}
