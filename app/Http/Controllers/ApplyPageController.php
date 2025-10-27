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

    public function __construct(private readonly PageRenderer $renderer)
    {
    }

    /**
     * Display the published Apply page, falling back gracefully when no content exists.
     */
    public function show(): View
    {
        $expiresAt = now()->addMinutes(self::CACHE_TTL_MINUTES);

        $content = Cache::remember(self::CACHE_KEY, $expiresAt, function (): ?string {
            $page = Page::query()
                ->where('slug', 'apply')
                ->where('status', Page::STATUS_PUBLISHED)
                ->first();

            if (! $page) {
                return null;
            }

            $blocks = $page->published;

            if (! is_array($blocks) || $blocks === []) {
                return null;
            }

            return $this->renderer->render($blocks);
        });

        return view('pages.apply', [
            'title' => 'Apply',
            'content' => $content,
        ]);
    }
}
