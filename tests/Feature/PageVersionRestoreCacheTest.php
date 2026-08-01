<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\PageVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PageVersionRestoreCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_restoring_a_published_version_invalidates_previously_rendered_html(): void
    {
        $page = Page::query()->create([
            'slug' => 'security-page',
            'status' => Page::STATUS_PUBLISHED,
            'published' => '<p>Unsafe old content</p>',
            'draft' => '<p>Unsafe old content</p>',
            'cached_html' => '<p>Unsafe old content</p>',
        ]);
        $version = $page->versions()->create([
            'editor_state' => '<p>Restored content</p>',
            'status' => PageVersion::STATUS_PUBLISHED,
            'published_at' => now()->subDay(),
        ]);
        Cache::put($page->cacheKey(), '<p>Unsafe old content</p>');

        $page->restoreFromVersion($version, restoreAsDraft: false);

        $this->assertNull($page->fresh()->cached_html);
        $this->assertFalse(Cache::has($page->cacheKey()));
        $this->assertSame(Page::STATUS_PUBLISHED, $page->fresh()->status);
    }
}
