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
        private readonly OffshoreTransferService $transferService
    ) {
    }

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

        return view('admin.offshores.index', [
            'offshores' => $offshores,
            'snapshots' => $snapshots,
            'transfers' => $transfers,
            'resources' => PWHelperService::resources(includeMoney: true, includeCredits: true),
            'guardrailResources' => OffshoreGuardrail::RESOURCES,
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

        return redirect()->route('admin.offshores.index')->with([
            'alert-message' => 'Offshore added successfully.',
            'alert-type' => 'success',
        ]);
    }

    public function update(UpdateOffshoreRequest $request, Offshore $offshore): RedirectResponse
    {
        $this->offshoreService->update($offshore, $request->payload(), $request->guardrails());

        event(new OffshoreCacheInvalidated($offshore->id, 'updated'));

        return redirect()->route('admin.offshores.index')->with([
            'alert-message' => 'Offshore updated successfully.',
            'alert-type' => 'success',
        ]);
    }

    public function destroy(Offshore $offshore): RedirectResponse
    {
        Gate::authorize('manage-offshores');

        $this->offshoreService->delete($offshore);

        event(new OffshoreCacheInvalidated($offshore->id, 'deleted'));

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

        $order = collect($data['order'] ?? [])->map(fn($priority) => (int) $priority);

        $offshores = Offshore::query()->whereIn('id', $order->keys())->get();

        foreach ($offshores as $offshore) {
            $newPriority = $order->get($offshore->id, $offshore->priority);

            if ($newPriority === $offshore->priority) {
                continue;
            }

            $this->offshoreService->update($offshore, ['priority' => $newPriority]);
            event(new OffshoreCacheInvalidated($offshore->id, 'priority'));
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

        return redirect()->route('admin.offshores.index')->with([
            'alert-message' => 'Balances refreshed: ' . implode(', ', $this->formatBalancesForMessage($balances)),
            'alert-type' => 'success',
        ]);
    }

    public function transfer(ManualOffshoreTransferRequest $request): RedirectResponse
    {
        $source = null;
        $destination = null;

        if ($request->input('source_type') === OffshoreTransfer::TYPE_OFFSHORE) {
            $source = Offshore::findOrFail((int) $request->input('source_offshore_id'));
        }

        if ($request->input('destination_type') === OffshoreTransfer::TYPE_OFFSHORE) {
            $destination = Offshore::findOrFail((int) $request->input('destination_offshore_id'));
        }

        try {
            $this->transferService->transfer(
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
                'alert-message' => 'Transfer failed: ' . $exception->getMessage(),
                'alert-type' => 'error',
            ]);
        }

        return redirect()->route('admin.offshores.index')->with([
            'alert-message' => 'Manual transfer dispatched successfully.',
            'alert-type' => 'success',
        ]);
    }

    /**
     * @param array<string, float> $balances
     * @return array<int, string>
     */
    protected function formatBalancesForMessage(array $balances): array
    {
        return collect($balances)
            ->filter(fn(float $amount) => $amount > 0)
            ->map(function (float $amount, string $resource) {
                $formatted = number_format($amount, 2);

                return sprintf('%s: %s', ucfirst($resource), $resource === 'money' ? '$' . $formatted : $formatted);
            })
            ->values()
            ->all();
    }
}
