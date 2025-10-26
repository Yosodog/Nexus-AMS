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
        protected OffshoreService $offshoreService
    ) {
        $this->mainAllianceId = (int) env('PW_ALLIANCE_ID');
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
        if ($this->mainAllianceId === 0) {
            Log::error('Main alliance id not configured; cannot perform offshore fulfillment', [
                'transaction_id' => $transaction->id,
            ]);

            return new OffshoreFulfillmentResult(
                OffshoreFulfillmentResult::STATUS_FAILED,
                'Missing PW_ALLIANCE_ID configuration for offshore fulfillment.',
                errors: [
                    [
                        'message' => 'PW_ALLIANCE_ID environment variable is not set.',
                    ],
                ]
            );
        }

        try {
            return Cache::lock(self::LOCK_KEY, self::LOCK_TTL_SECONDS)->block(
                self::LOCK_WAIT_SECONDS,
                fn() => $this->performFulfillment($transaction)
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
     * Calculate the resource gaps and sequentially top them up from the
     * prioritized offshore list.
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

        $initialDeficits = $deficits;
        $transfers = [];
        $errors = [];
        $guardrailBlocks = [];

        /** @var Offshore[] $offshores */
        $offshores = $this->offshoreService->all();

        // Offshores are already ordered by priority; walk them until the deficit disappears.
        foreach ($offshores as $offshore) {
            if (empty($deficits)) {
                break;
            }

            $balances = $this->offshoreService->getBalances($offshore);
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

            try {
                // Issue the targeted withdrawal before re-checking the next offshore.
                $this->sendOffshoreWithdrawal($offshore, $transaction, $withdrawalPayload);
                $this->offshoreService->refreshBalances($offshore);

                foreach ($withdrawalPayload as $resource => $amount) {
                    $deficits[$resource] -= $amount;

                    if ($deficits[$resource] <= 0.0001) {
                        unset($deficits[$resource]);
                    }
                }

                // Capture every successful transfer so admins can review the path taken.
                $transfers[] = [
                    'offshore_id' => $offshore->id,
                    'offshore_name' => $offshore->name,
                    'resources' => $withdrawalPayload,
                ];
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
            } catch (Throwable $exception) {
                Log::error('Unexpected error during offshore fulfillment', [
                    'transaction_id' => $transaction->id,
                    'offshore_id' => $offshore->id,
                    'message' => $exception->getMessage(),
                ]);

                $errors[] = [
                    'offshore_id' => $offshore->id,
                    'offshore_name' => $offshore->name,
                    'message' => 'Unexpected error: ' . $exception->getMessage(),
                ];
            }
        }

        if (! empty($deficits)) {
            Log::warning('Unable to fully cover transaction shortfall via offshores', [
                'transaction_id' => $transaction->id,
                'remaining_deficits' => $deficits,
            ]);

            return new OffshoreFulfillmentResult(
                OffshoreFulfillmentResult::STATUS_FAILED,
                'Insufficient offshore liquidity to fulfill this transaction automatically.',
                transfers: $transfers,
                errors: $errors,
                guardrailBlocks: $guardrailBlocks,
                remainingDeficits: $deficits,
                initialDeficits: $initialDeficits
            );
        }

        return new OffshoreFulfillmentResult(
            OffshoreFulfillmentResult::STATUS_FULFILLED,
            'Offshore transfers successfully covered the main bank shortfall.',
            transfers: $transfers,
            errors: $errors,
            guardrailBlocks: $guardrailBlocks,
            remainingDeficits: $deficits,
            initialDeficits: $initialDeficits
        );
    }

    /**
     * Fetch a snapshot of the main alliance bank or null if the query fails.
     */
    protected function getMainAllianceBalances(): ?array
    {
        $builder = (new GraphQLQueryBuilder())
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

        $result = (array)($response->{0} ?? []);
        $resources = PWHelperService::resources();

        return collect($resources)
            ->mapWithKeys(fn(string $resource) => [
                $resource => (float) Arr::get($result, $resource, 0),
            ])
            ->all();
    }

    /**
     * @param array<string, float> $payload
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

        $builder = (new GraphQLQueryBuilder())
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
