<?php

namespace App\Http\Controllers;

use App\Models\Page;
use App\Services\PageRenderer;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;

/**
 * Expose the public application landing page driven by the CMS.
 */
class ApplyPageController extends Controller
{
    private const CACHE_KEY = 'pages:apply:html';

    private const CACHE_TTL_MINUTES = 5;

    public function __construct(private readonly PageRenderer $renderer) {}

    /**
     * Display the published Apply page, falling back gracefully when no content exists.
     */
    public function show(): View
    {
        $expiresAt = now()->addMinutes(self::CACHE_TTL_MINUTES);

        $content = Cache::remember(self::CACHE_KEY, $expiresAt, function (): ?string {
            $page = Page::query()
                ->where('slug', 'apply')
                ->first();

            if (! $page) {
                return null;
            }

            if (is_string($page->cached_html) && trim($page->cached_html) !== '') {
                return $page->cached_html;
            }

            $published = is_string($page->published) ? $page->published : '';

            if ($published === '') {
                return null;
            }

            $html = $this->renderer->render($published);
            $page->forceFill(['cached_html' => $html])->save();

            return $html;
        });

        return view('pages.apply', [
            'title' => 'Apply',
            'content' => $content,
        ]);
    }
}
