<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\ApplyPageController;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Customization\CustomizationDraftRequest;
use App\Http\Requests\Admin\Customization\CustomizationPageRequest;
use App\Http\Requests\Admin\Customization\CustomizationPreviewRequest;
use App\Http\Requests\Admin\Customization\CustomizationPublishRequest;
use App\Http\Requests\Admin\Customization\CustomizationRestoreRequest;
use App\Http\Requests\Admin\Customization\CustomizationUnpublishRequest;
use App\Models\Page;
use App\Models\PageActivityLog;
use App\Models\PageVersion;
use App\Services\PagePublisher;
use App\Services\PageRenderer;
use App\Services\SeoService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;

/**
 * Handle administrative customization workflows for CMS-driven pages.
 */
class CustomizationController extends Controller
{
    public function __construct(
        private readonly PagePublisher $publisher,
        private readonly PageRenderer $renderer,
        private readonly ApplyPageController $applyPageController,
        private readonly SeoService $seoService,
    ) {}

    /**
     * Display the list of managed pages.
     */
    public function index(): View
    {
        $this->authorize('manage-custom-pages');

        $pages = Page::query()
            ->with(['latestPublishedVersion.user'])
            ->orderBy('slug')
            ->get();

        return view('admin.customization.index', [
            'pages' => $pages,
            'pageStates' => $pages->mapWithKeys(fn (Page $page): array => [
                $page->id => $this->serializePageState($page),
            ]),
        ]);
    }

    /**
     * Render the new page form.
     */
    public function create(): View
    {
        $this->authorize('manage-custom-pages');

        return view('admin.customization.create');
    }

    /**
     * Create a page with an initial draft and open it in the editor.
     */
    public function store(CustomizationPageRequest $request): RedirectResponse
    {
        $this->authorize('manage-custom-pages');

        $user = $request->user();
        $pageMetadata = $request->pageMetadata();
        $page = Page::query()->create([
            'slug' => $request->slug(),
            'status' => Page::STATUS_DRAFT,
            'draft' => '',
            'draft_metadata' => $pageMetadata,
        ]);

        $this->publisher->saveDraft($page, '', $user, [
            'origin' => 'admin-create',
        ], $pageMetadata);

        return redirect()
            ->route('admin.customization.edit', $page)
            ->with('status', 'Page created as a draft.');
    }

    /**
     * Render the editor UI for a specific page.
     */
    public function edit(Page $page): View
    {
        $this->authorize('manage-custom-pages');

        $pages = Page::query()->orderBy('slug')->get(['id', 'slug', 'status', 'draft_metadata', 'published_metadata']);
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
            'pageMetadata' => $this->pageMetadata($page),
            'pageState' => $this->serializePageState($page),
        ]);
    }

    /**
     * Produce a non-persistent preview of a page draft.
     */
    public function preview(CustomizationPreviewRequest $request, Page $page): JsonResponse
    {
        $this->authorize('manage-custom-pages');

        $content = $this->publisher->normalizeContent($request->content());
        $html = $this->renderer->render($content);

        if ($page->slug === 'apply') {
            $document = $this->applyPageController->preview($request, $html)->render();
        } else {
            $metadata = $request->pageMetadata()
                ?? $this->pageMetadata($page)
                ?? ['title' => Str::headline($page->slug), 'description' => null, 'audience' => 'public'];
            $previewPage = clone $page;
            $previewPage->published_metadata = $metadata;

            $document = view('pages.show', [
                'title' => $metadata['title'],
                'content' => $html,
                'pageLayout' => $metadata['audience'] === 'member' ? 'layouts.main' : 'layouts.public',
                'seo' => $metadata['audience'] === 'public' ? $this->seoService->pageMetadata($previewPage) : null,
            ])->render();
        }

        return response()->json([
            'html' => $html,
            'document' => $document,
        ]);
    }

    /**
     * Persist the submitted draft content without publishing it.
     */
    public function saveDraft(CustomizationDraftRequest $request, Page $page): JsonResponse
    {
        $this->authorize('manage-custom-pages');

        $user = $request->user();
        $content = $this->publisher->normalizeContent($request->content());
        $version = $this->publisher->saveDraft(
            $page,
            $content,
            $user,
            $request->metadata(),
            $request->pageMetadata(),
        );

        return response()->json([
            'version' => $this->serializeVersion($version),
            'page' => $this->serializePageState($page->refresh()),
        ]);
    }

    /**
     * Publish the current draft and cache the rendered HTML.
     */
    public function publish(CustomizationPublishRequest $request, Page $page): JsonResponse
    {
        $this->authorize('manage-custom-pages');

        $user = $request->user();
        $content = $this->publisher->normalizeContent($request->content());
        $html = $this->renderer->render($content);
        $version = $this->publisher->publish(
            $page,
            $content,
            $html,
            $user,
            null,
            $request->pageMetadata(),
        );

        return response()->json([
            'html' => $html,
            'version' => $this->serializeVersion($version),
            'page' => $this->serializePageState($page->refresh()),
        ]);
    }

    /**
     * Return recent versions and activity logs for audit purposes.
     */
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

    /**
     * Remove a page from the live site while retaining its content and history.
     */
    public function unpublish(CustomizationUnpublishRequest $request, Page $page): JsonResponse
    {
        $this->authorize('manage-custom-pages');

        if ($page->slug === 'apply') {
            return response()->json([
                'message' => 'The special apply page cannot be unpublished.',
            ], 422);
        }

        $page->unpublish($request->user());

        return response()->json([
            'page' => $this->serializePageState($page->refresh()),
        ]);
    }

    /**
     * Restore a historical version either as a draft or a published revision.
     */
    public function restore(CustomizationRestoreRequest $request, Page $page): JsonResponse
    {
        $this->authorize('manage-custom-pages');

        $user = $request->user();
        $sourceVersion = $page->versions()->findOrFail($request->versionId());
        $content = $this->publisher->normalizeContent($sourceVersion->editor_state ?? '');

        if ($request->shouldPublish()) {
            $html = $this->renderer->render($content);
            $restoredVersion = $this->publisher->publish(
                $page,
                $content,
                $html,
                $user,
                null,
                $sourceVersion->page_metadata,
            );
        } else {
            $restoredVersion = $this->publisher->saveDraft(
                $page,
                $content,
                $user,
                [
                    'restored_from_version' => $sourceVersion->id,
                ],
                $sourceVersion->page_metadata,
            );
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
            'content' => $content,
            'version' => $this->serializeVersion($restoredVersion),
            'page' => $this->serializePageState($page->refresh()),
        ], fn ($value) => $value !== null));
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeVersion(PageVersion $version): array
    {
        return [
            'id' => $version->id,
            'status' => $version->status,
            'editor_state' => $version->editor_state,
            'content' => $version->editor_state,
            'created_at' => $version->created_at?->toIso8601String(),
            'published_at' => $version->published_at?->toIso8601String(),
            'page_metadata' => $version->page_metadata,
            'user' => $version->user?->only(['id', 'name']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
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

    /**
     * @return array<string, mixed>
     */
    private function serializePageState(Page $page): array
    {
        $pageMetadata = $this->pageMetadata($page);

        return [
            'id' => $page->id,
            'slug' => $page->slug,
            'status' => $page->status,
            'status_label' => $this->pageStatusLabel($page),
            'is_live' => $this->isLive($page),
            'is_special' => $page->slug === 'apply',
            'draft_metadata' => $this->attributeArray($page, 'draft_metadata'),
            'published_metadata' => $this->attributeArray($page, 'published_metadata'),
            'page_metadata' => $pageMetadata,
            'draft' => $page->draft,
            'published' => $page->published,
        ];
    }

    /**
     * @return array{title: string, description: string|null, audience: string}|null
     */
    private function pageMetadata(Page $page): ?array
    {
        $draftMetadata = $this->attributeArray($page, 'draft_metadata');
        $publishedMetadata = $this->attributeArray($page, 'published_metadata');
        $metadata = $draftMetadata ?: $publishedMetadata;

        if ($page->slug === 'apply' || $metadata === []) {
            return null;
        }

        return [
            'title' => (string) ($metadata['title'] ?? ''),
            'description' => isset($metadata['description']) && $metadata['description'] !== ''
                ? (string) $metadata['description']
                : null,
            'audience' => (string) ($metadata['audience'] ?? 'public'),
        ];
    }

    private function pageStatusLabel(Page $page): string
    {
        if ($this->isLive($page)) {
            return $page->status === Page::STATUS_PUBLISHED
                ? 'Live'
                : 'Live with unpublished changes';
        }

        return $page->latestPublishedVersion()->exists()
            ? 'Unpublished'
            : 'Never published';
    }

    private function isLive(Page $page): bool
    {
        return $page->hasPublishedContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function attributeArray(Page $page, string $attribute): array
    {
        $value = $page->getAttribute($attribute);

        return is_array($value) ? $value : [];
    }
}
