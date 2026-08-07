<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StaffWorkQueueRequest;
use App\Http\Requests\Admin\StoreStaffWorkQueueSavedViewRequest;
use App\Models\User;
use App\Services\StaffWorkQueue\StaffWorkQueueFilterSet;
use App\Services\StaffWorkQueue\StaffWorkQueueQuery;
use App\Services\StaffWorkQueue\StaffWorkQueueRegistry;
use App\Services\StaffWorkQueue\StaffWorkQueueSavedViewStore;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class StaffWorkQueueController extends Controller
{
    public function __construct(
        private readonly StaffWorkQueueRegistry $registry,
        private readonly StaffWorkQueueQuery $query,
        private readonly StaffWorkQueueSavedViewStore $savedViews,
    ) {}

    public function index(StaffWorkQueueRequest $request): View|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $projection = $this->registry->forUser($user, $request->boolean('refresh'));
        $filters = $request->filters();
        $selectedSavedView = null;

        if ($savedViewId = $request->validated('saved_view')) {
            $selectedSavedView = $this->savedViews->find($user, $savedViewId);

            if ($selectedSavedView === null) {
                abort(404);
            }

            try {
                $filters = StaffWorkQueueFilterSet::fromArray(
                    $selectedSavedView['filters'],
                    array_keys($projection['types']),
                );
            } catch (ValidationException) {
                return redirect()
                    ->route('admin.work-queue.index')
                    ->with('alert-type', 'warning')
                    ->with('alert-message', 'That saved view contains filters you can no longer use. Update or delete it before restoring it.');
            }
        }

        $queryParameters = $selectedSavedView
            ? ['saved_view' => $selectedSavedView['id']]
            : $filters->toArray();
        $items = $this->query->paginate(
            items: $projection['items'],
            filters: $filters,
            page: (int) ($request->validated('page') ?? 1),
            perPage: 25,
            queryParameters: $queryParameters,
        );

        return view('admin.work-queue.index', [
            'items' => $items,
            'filters' => $filters,
            'types' => $projection['types'],
            'owners' => $this->query->ownerOptions($projection['items']),
            'counts' => $projection['counts'],
            'unfilteredTotal' => $projection['total'],
            'failures' => $projection['failures'],
            'generatedAt' => CarbonImmutable::parse($projection['generated_at']),
            'savedViews' => $this->savedViews->all($user),
            'selectedSavedView' => $selectedSavedView,
        ]);
    }

    public function storeSavedView(StoreStaffWorkQueueSavedViewRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $savedViewId = $this->savedViews->save(
            $user,
            (string) $request->validated('name'),
            $request->filters(),
        );

        return redirect()
            ->route('admin.work-queue.index', ['saved_view' => $savedViewId])
            ->with('alert-type', 'success')
            ->with('alert-message', 'Work queue view saved.');
    }

    public function destroySavedView(StaffWorkQueueRequest $request, string $savedView): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $deleted = $this->savedViews->delete($user, $savedView);

        abort_unless($deleted, 404);

        return redirect()
            ->route('admin.work-queue.index')
            ->with('alert-type', 'success')
            ->with('alert-message', 'Saved work queue view removed.');
    }
}
