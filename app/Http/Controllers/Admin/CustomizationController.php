<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Customization\CustomizationDraftRequest;
use App\Http\Requests\Admin\Customization\CustomizationPreviewRequest;
use App\Http\Requests\Admin\Customization\CustomizationPublishRequest;
use App\Http\Requests\Admin\Customization\CustomizationRestoreRequest;
use App\Models\Page;
use App\Models\PageActivityLog;
use App\Models\PageVersion;
use App\Services\PagePublisher;
use App\Services\PageRenderer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class CustomizationController extends Controller
{
    public function __construct(
        private readonly PagePublisher $publisher,
        private readonly PageRenderer $renderer,
    ) {
    }

    public function index(): View
    {
        $this->authorize('manage-custom-pages');

        $pages = Page::query()
            ->with(['latestPublishedVersion.user'])
            ->orderBy('slug')
            ->get();

        return view('admin.customization.index', [
            'pages' => $pages,
        ]);
    }

    public function edit(Page $page): View
    {
        $this->authorize('manage-custom-pages');

        $pages = Page::query()->orderBy('slug')->get(['id', 'slug', 'status']);
        $latestDraft = $page->versions()
            ->with('user')
            ->where('status', PageVersion::STATUS_DRAFT)
            ->latest('created_at')
            ->first();
        $latestPublished = $page->latestPublishedVersion()->with('user')->first();
        $recentActivity = $page->activityLogs()->with('user')->latest('created_at')->limit(10)->get();

        return view('admin.customization.edit', [
            'page' => $page,
            'pages' => $pages,
            'latestDraft' => $latestDraft,
            'latestPublished' => $latestPublished,
            'recentActivity' => $recentActivity,
        ]);
    }

    public function preview(CustomizationPreviewRequest $request, Page $page): JsonResponse
    {
        $this->authorize('manage-custom-pages');

        $user = $request->user();
        $normalized = $this->publisher->normalizeBlocks($request->blocks());
        $html = $this->renderer->render($normalized);

        $version = $page->versions()->create([
            'editor_state' => $normalized,
            'status' => PageVersion::STATUS_PREVIEW,
            'user_id' => $user?->id,
        ]);

        $page->activityLogs()->create([
            'action' => PageActivityLog::ACTION_PREVIEWED,
            'user_id' => $user?->id,
            'metadata' => array_merge($request->metadata(), [
                'version_id' => $version->id,
                'previewed_at' => Carbon::now()->toIso8601String(),
            ]),
        ]);

        $page->forgetCachedHtml();

        return response()->json([
            'html' => $html,
            'version' => $this->serializeVersion($version),
        ]);
    }

    public function saveDraft(CustomizationDraftRequest $request, Page $page): JsonResponse
    {
        $this->authorize('manage-custom-pages');

        $user = $request->user();
        $normalized = $this->publisher->normalizeBlocks($request->blocks());
        $version = $this->publisher->saveDraft($page, $normalized, $user, $request->metadata());

        return response()->json([
            'version' => $this->serializeVersion($version),
            'page' => $this->serializePageState($page->refresh()),
        ]);
    }

    public function publish(CustomizationPublishRequest $request, Page $page): JsonResponse
    {
        $this->authorize('manage-custom-pages');

        $user = $request->user();
        $normalized = $this->publisher->normalizeBlocks($request->blocks());
        $html = $this->renderer->render($normalized);
        $version = $this->publisher->publish($page, $normalized, $html, $user);

        return response()->json([
            'html' => $html,
            'version' => $this->serializeVersion($version),
            'page' => $this->serializePageState($page->refresh()),
        ]);
    }

    public function versions(Page $page): JsonResponse
    {
        $this->authorize('manage-custom-pages');

        $versions = $page->versions()->with('user')->latest('created_at')->limit(25)->get();
        $activity = $page->activityLogs()->with('user')->latest('created_at')->limit(25)->get();

        return response()->json([
            'versions' => $versions->map(fn (PageVersion $version) => $this->serializeVersion($version))->all(),
            'activity' => $activity->map(fn (PageActivityLog $log) => $this->serializeActivity($log))->all(),
        ]);
    }

    public function restore(CustomizationRestoreRequest $request, Page $page): JsonResponse
    {
        $this->authorize('manage-custom-pages');

        $user = $request->user();
        $sourceVersion = $page->versions()->findOrFail($request->versionId());
        $normalized = $this->publisher->normalizeBlocks($sourceVersion->editor_state);

        if ($request->shouldPublish()) {
            $html = $this->renderer->render($normalized);
            $restoredVersion = $this->publisher->publish($page, $normalized, $html, $user);
        } else {
            $restoredVersion = $this->publisher->saveDraft($page, $normalized, $user, [
                'restored_from_version' => $sourceVersion->id,
            ]);
            $html = null;
        }

        $page->activityLogs()->create([
            'action' => PageActivityLog::ACTION_RESTORED,
            'user_id' => $user?->id,
            'metadata' => array_filter([
                'source_version_id' => $sourceVersion->id,
                'restored_version_id' => $restoredVersion->id,
                'published' => $request->shouldPublish(),
            ]),
        ]);

        return response()->json(array_filter([
            'html' => $html,
            'version' => $this->serializeVersion($restoredVersion),
            'page' => $this->serializePageState($page->refresh()),
        ], fn ($value) => $value !== null));
    }

    private function serializeVersion(PageVersion $version): array
    {
        return [
            'id' => $version->id,
            'status' => $version->status,
            'editor_state' => $version->editor_state,
            'created_at' => $version->created_at?->toIso8601String(),
            'published_at' => $version->published_at?->toIso8601String(),
            'user' => $version->user?->only(['id', 'name']),
        ];
    }

    private function serializeActivity(PageActivityLog $log): array
    {
        return [
            'id' => $log->id,
            'action' => $log->action,
            'metadata' => $log->metadata,
            'created_at' => $log->created_at?->toIso8601String(),
            'user' => $log->user?->only(['id', 'name']),
        ];
    }

    private function serializePageState(Page $page): array
    {
        return [
            'id' => $page->id,
            'slug' => $page->slug,
            'status' => $page->status,
            'draft' => $page->draft,
            'published' => $page->published,
        ];
    }
}
