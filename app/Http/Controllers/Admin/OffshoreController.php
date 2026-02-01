<?php

namespace App\Http\Controllers\Admin;

use App\Events\OffshoreCacheInvalidated;
use App\Exceptions\OffshoreTransferException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ManualOffshoreTransferRequest;
use App\Http\Requests\Admin\StoreOffshoreRequest;
use App\Http\Requests\Admin\UpdateOffshoreRequest;
use App\Models\Offshore;
use App\Models\OffshoreGuardrail;
use App\Models\OffshoreTransfer;
use App\Services\AuditLogger;
use App\Services\MainBankService;
use App\Services\OffshoreService;
use App\Services\OffshoreTransferService;
use App\Services\PWHelperService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class OffshoreController extends Controller
{
    public function __construct(
        private readonly OffshoreService $offshoreService,
        private readonly OffshoreTransferService $transferService,
        private readonly MainBankService $mainBankService,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('view-offshores');

        $offshores = $this->offshoreService->all(includeDisabled: true);
        $snapshots = $offshores->mapWithKeys(function (Offshore $offshore) {
            return [$offshore->id => $this->offshoreService->getCachedSnapshot($offshore)];
        });

        $transfers = OffshoreTransfer::with(['user', 'sourceOffshore', 'destinationOffshore'])
            ->latest()
            ->limit(15)
            ->get();

        $mainBankSnapshot = $this->mainBankService->getCachedSnapshot();

        return view('admin.offshores.index', [
            'offshores' => $offshores,
            'snapshots' => $snapshots,
            'transfers' => $transfers,
            'resources' => PWHelperService::resources(),
            'guardrailResources' => OffshoreGuardrail::RESOURCES,
            'mainBankSnapshot' => $mainBankSnapshot,
            'showCreateModal' => $request->session()->pull('show-offshore-modal') === 'create',
            'editOffshoreId' => $request->session()->pull('edit-offshore-id'),
        ]);
    }

    public function create(): RedirectResponse
    {
        Gate::authorize('manage-offshores');

        return redirect()
            ->route('admin.offshores.index')
            ->with('show-offshore-modal', 'create');
    }

    public function edit(Offshore $offshore): RedirectResponse
    {
        Gate::authorize('manage-offshores');

        return redirect()
            ->route('admin.offshores.index')
            ->with('edit-offshore-id', $offshore->id);
    }

    public function store(StoreOffshoreRequest $request): RedirectResponse
    {
        $offshore = $this->offshoreService->create($request->payload(), $request->guardrails());

        event(new OffshoreCacheInvalidated($offshore->id, 'created'));

        $payload = collect($request->payload())
            ->except(['api_key', 'mutation_key'])
            ->all();

        $this->auditLogger->recordAfterCommit(
            category: 'offshore',
            action: 'offshore_created',
            outcome: 'success',
            severity: 'warning',
            subject: $offshore,
            context: [
                'data' => [
                    'payload' => $payload,
                    'guardrails' => $request->guardrails(),
                ],
            ],
            message: 'Offshore created.'
        );

        return redirect()->route('admin.offshores.index')->with([
            'alert-message' => 'Offshore added successfully.',
            'alert-type' => 'success',
        ]);
    }

    public function update(UpdateOffshoreRequest $request, Offshore $offshore): RedirectResponse
    {
        $before = $offshore->only(['name', 'alliance_id', 'enabled', 'priority']);
        $beforeGuardrails = $offshore->guardrails()->get()->mapWithKeys(
            fn (OffshoreGuardrail $guardrail) => [$guardrail->resource => $guardrail->minimum_amount]
        )->all();

        $this->offshoreService->update($offshore, $request->payload(), $request->guardrails());

        event(new OffshoreCacheInvalidated($offshore->id, 'updated'));

        $after = $offshore->fresh(['guardrails'])->only(['name', 'alliance_id', 'enabled', 'priority']);
        $afterGuardrails = $offshore->guardrails->mapWithKeys(
            fn (OffshoreGuardrail $guardrail) => [$guardrail->resource => $guardrail->minimum_amount]
        )->all();
        $changes = [];

        foreach ($after as $field => $value) {
            if ((string) ($before[$field] ?? null) !== (string) $value) {
                $changes[$field] = [
                    'from' => $before[$field] ?? null,
                    'to' => $value,
                ];
            }
        }

        if ($beforeGuardrails !== $afterGuardrails) {
            $changes['guardrails'] = [
                'from' => $beforeGuardrails,
                'to' => $afterGuardrails,
            ];
        }

        $this->auditLogger->recordAfterCommit(
            category: 'offshore',
            action: 'offshore_updated',
            outcome: 'success',
            severity: 'warning',
            subject: $offshore,
            context: [
                'changes' => $changes,
            ],
            message: 'Offshore updated.'
        );

        return redirect()->route('admin.offshores.index')->with([
            'alert-message' => 'Offshore updated successfully.',
            'alert-type' => 'success',
        ]);
    }

    public function destroy(Offshore $offshore): RedirectResponse
    {
        Gate::authorize('manage-offshores');

        $snapshot = $offshore->only(['name', 'alliance_id', 'enabled', 'priority']);
        $guardrails = $offshore->guardrails()->get()->mapWithKeys(
            fn (OffshoreGuardrail $guardrail) => [$guardrail->resource => $guardrail->minimum_amount]
        )->all();

        $this->offshoreService->delete($offshore);

        event(new OffshoreCacheInvalidated($offshore->id, 'deleted'));

        $this->auditLogger->recordAfterCommit(
            category: 'offshore',
            action: 'offshore_deleted',
            outcome: 'success',
            severity: 'warning',
            subject: $offshore,
            context: [
                'data' => [
                    ...$snapshot,
                    'guardrails' => $guardrails,
                ],
            ],
            message: 'Offshore deleted.'
        );

        return redirect()->route('admin.offshores.index')->with([
            'alert-message' => 'Offshore deleted successfully.',
            'alert-type' => 'success',
        ]);
    }

    public function reorder(Request $request): RedirectResponse
    {
        Gate::authorize('manage-offshores');

        $data = $request->validate([
            'order' => ['required', 'array'],
            'order.*' => ['integer'],
        ]);

        $order = collect($data['order'] ?? [])->mapWithKeys(
            fn ($priority, $id) => [(int) $id => (int) $priority]
        );

        $offshores = Offshore::query()->whereIn('id', $order->keys())->get();
        $changes = [];

        foreach ($offshores as $offshore) {
            $newPriority = $order->get($offshore->id, $offshore->priority);

            if ($newPriority === $offshore->priority) {
                continue;
            }

            $changes[] = [
                'id' => $offshore->id,
                'from' => $offshore->priority,
                'to' => $newPriority,
            ];

            $this->offshoreService->update($offshore, ['priority' => $newPriority]);
            event(new OffshoreCacheInvalidated($offshore->id, 'priority'));
        }

        if ($changes !== []) {
            $this->auditLogger->recordAfterCommit(
                category: 'offshore',
                action: 'offshore_priorities_updated',
                outcome: 'success',
                severity: 'warning',
                context: [
                    'changes' => $changes,
                ],
                message: 'Offshore priorities updated.'
            );
        }

        return redirect()->route('admin.offshores.index')->with([
            'alert-message' => 'Priorities updated.',
            'alert-type' => 'success',
        ]);
    }

    public function toggle(Offshore $offshore): RedirectResponse
    {
        Gate::authorize('manage-offshores');

        $updated = $this->offshoreService->update($offshore, [
            'enabled' => ! $offshore->enabled,
        ]);

        event(new OffshoreCacheInvalidated($updated->id, 'toggled'));

        $this->auditLogger->recordAfterCommit(
            category: 'offshore',
            action: 'offshore_toggled',
            outcome: 'success',
            severity: 'warning',
            subject: $updated,
            context: [
                'changes' => [
                    'enabled' => [
                        'from' => $offshore->enabled,
                        'to' => $updated->enabled,
                    ],
                ],
            ],
            message: 'Offshore toggled.'
        );

        return redirect()->route('admin.offshores.index')->with([
            'alert-message' => sprintf('%s is now %s.', $updated->name, $updated->enabled ? 'enabled' : 'disabled'),
            'alert-type' => 'success',
        ]);
    }

    public function refresh(Offshore $offshore): RedirectResponse
    {
        Gate::authorize('manage-offshores');

        $balances = $this->offshoreService->refreshBalances($offshore, true);

        event(new OffshoreCacheInvalidated($offshore->id, 'refresh'));

        $this->auditLogger->success(
            category: 'offshore',
            action: 'offshore_balances_refreshed',
            subject: $offshore,
            context: [
                'data' => [
                    'balances' => $balances,
                ],
            ],
            message: 'Offshore balances refreshed.'
        );

        return redirect()->route('admin.offshores.index')->with([
            'alert-message' => 'Balances refreshed: '.implode(', ', $this->formatBalancesForMessage($balances)),
            'alert-type' => 'success',
        ]);
    }

    public function transfer(ManualOffshoreTransferRequest $request): RedirectResponse
    {
        $source = null;
        $destination = null;
        $transfer = null;

        if ($request->input('source_type') === OffshoreTransfer::TYPE_OFFSHORE) {
            $source = Offshore::findOrFail((int) $request->input('source_offshore_id'));
        }

        if ($request->input('destination_type') === OffshoreTransfer::TYPE_OFFSHORE) {
            $destination = Offshore::findOrFail((int) $request->input('destination_offshore_id'));
        }

        try {
            $transfer = $this->transferService->transfer(
                $request->input('source_type'),
                $source,
                $request->input('destination_type'),
                $destination,
                $request->validatedResources(),
                $request->user(),
                $request->input('note')
            );
        } catch (OffshoreTransferException $exception) {
            return redirect()->route('admin.offshores.index')->with([
                'alert-message' => 'Transfer failed: '.$exception->getMessage(),
                'alert-type' => 'error',
            ]);
        }

        $this->auditLogger->recordAfterCommit(
            category: 'offshore',
            action: 'offshore_transfer_manual',
            outcome: 'success',
            severity: 'warning',
            subject: $transfer,
            context: [
                'data' => [
                    'source_type' => $request->input('source_type'),
                    'source_offshore_id' => $source?->id,
                    'destination_type' => $request->input('destination_type'),
                    'destination_offshore_id' => $destination?->id,
                    'resources' => $request->validatedResources(),
                    'note' => $request->input('note'),
                ],
            ],
            message: 'Manual offshore transfer dispatched.'
        );

        return redirect()->route('admin.offshores.index')->with([
            'alert-message' => 'Manual transfer dispatched successfully.',
            'alert-type' => 'success',
        ]);
    }

    public function refreshMainBank(): RedirectResponse
    {
        Gate::authorize('manage-offshores');

        $balances = $this->mainBankService->refreshBalances();

        $this->auditLogger->success(
            category: 'offshore',
            action: 'main_bank_balances_refreshed',
            context: [
                'data' => [
                    'balances' => $balances,
                ],
            ],
            message: 'Main bank balances refreshed.'
        );

        return redirect()->route('admin.offshores.index')->with([
            'alert-message' => 'Main bank balances refreshed: '.implode(', ', $this->formatBalancesForMessage($balances)),
            'alert-type' => 'success',
        ]);
    }

    public function sweepToOffshore(Request $request, Offshore $offshore): RedirectResponse
    {
        Gate::authorize('manage-offshores');

        $balances = $this->mainBankService->refreshBalances();
        $transfer = null;

        $payload = collect(PWHelperService::resources())
            ->mapWithKeys(fn (string $resource) => [
                $resource => (float) ($balances[$resource] ?? 0),
            ])
            ->filter(fn (float $amount) => $amount > 0)
            ->all();

        if (empty($payload)) {
            return redirect()->route('admin.offshores.index')->with([
                'alert-message' => 'Main bank is already empty—no transfer was dispatched.',
                'alert-type' => 'info',
            ]);
        }

        try {
            $transfer = $this->transferService->transfer(
                OffshoreTransfer::TYPE_MAIN,
                null,
                OffshoreTransfer::TYPE_OFFSHORE,
                $offshore,
                $payload,
                $request->user(),
                sprintf('Main bank sweep into %s', $offshore->name)
            );
        } catch (OffshoreTransferException $exception) {
            return redirect()->route('admin.offshores.index')->with([
                'alert-message' => 'Main bank sweep failed: '.$exception->getMessage(),
                'alert-type' => 'error',
            ]);
        }

        $this->mainBankService->refreshBalances();
        $this->offshoreService->refreshBalances($offshore, true);
        event(new OffshoreCacheInvalidated($offshore->id, 'main-bank-sweep'));

        $this->auditLogger->recordAfterCommit(
            category: 'offshore',
            action: 'main_bank_sweep',
            outcome: 'success',
            severity: 'warning',
            subject: $transfer,
            context: [
                'related' => [
                    ['type' => 'Offshore', 'id' => (string) $offshore->id, 'role' => 'destination'],
                ],
                'data' => [
                    'resources' => $payload,
                ],
            ],
            message: 'Main bank sweep dispatched.'
        );

        return redirect()->route('admin.offshores.index')->with([
            'alert-message' => sprintf('Main bank sweep dispatched to %s.', $offshore->name),
            'alert-type' => 'success',
        ]);
    }

    /**
     * @param  array<string, float>  $balances
     * @return array<int, string>
     */
    protected function formatBalancesForMessage(array $balances): array
    {
        return collect($balances)
            ->filter(fn (float $amount) => $amount > 0)
            ->map(function (float $amount, string $resource) {
                $formatted = number_format($amount, 2);

                return sprintf('%s: %s', ucfirst($resource), $resource === 'money' ? '$'.$formatted : $formatted);
            })
            ->values()
            ->all();
    }
}
