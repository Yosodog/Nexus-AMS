<?php

namespace App\Http\Controllers;

use App\Models\Page;
use App\Services\PageRenderer;
use App\Services\SeoService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class PageController extends Controller
{
    public function __construct(
        private readonly PageRenderer $renderer,
        private readonly SeoService $seoService,
    ) {}

    public function show(Request $request): View
    {
        /** @var Page $page */
        $page = $request->attributes->get('custom_page');
        $metadata = $page->published_metadata;
        $content = Cache::remember(
            $page->cacheKey(),
            now()->addMinutes(5),
            fn (): string => is_string($page->cached_html) && trim($page->cached_html) !== ''
                ? $page->cached_html
                : $this->renderer->render($page->published ?? ''),
        );

        return view('pages.show', [
            'title' => $metadata['title'],
            'content' => $content,
            'pageLayout' => $metadata['audience'] === 'member' ? 'layouts.main' : 'layouts.public',
            'seo' => $metadata['audience'] === 'public' ? $this->seoService->pageMetadata($page) : null,
        ]);
    }
}
