<?php

namespace Tests\Feature;

use App\Models\Alliance;
use App\Models\Page;
use App\Services\SeoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class CustomPageSeoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.name', 'BK Net');
        config()->set('app.url', 'https://bk.example.com');
        config()->set('seo.indexing_enabled', true);
        config()->set('services.pw.alliance_id', 123);
        URL::useOrigin('https://bk.example.com');
        URL::forceScheme('https');

        Alliance::factory()->create([
            'id' => 123,
            'name' => 'Black Knights',
            'acronym' => 'BK',
            'flag' => 'https://cdn.example.com/black-knights.png',
        ]);
    }

    public function test_public_custom_page_metadata_and_sitemap_entry_are_indexable(): void
    {
        $page = $this->createPublishedPage('about', [
            'title' => 'About the Alliance',
            'description' => 'Learn how the alliance works.',
            'audience' => 'public',
        ]);

        $metadata = app(SeoService::class)->pageMetadata($page);

        $this->assertSame('About the Alliance', $metadata->title);
        $this->assertSame('Learn how the alliance works.', $metadata->description);
        $this->assertSame('https://bk.example.com/pages/about', $metadata->canonical);
        $this->assertSame('index, follow', $metadata->robots);
        $this->assertTrue($metadata->indexable);

        $this->get(route('pages.show', ['slug' => $page->slug]))
            ->assertOk()
            ->assertHeaderMissing('X-Robots-Tag')
            ->assertSee('<title>About the Alliance</title>', false)
            ->assertSee('<meta name="description" content="Learn how the alliance works.">', false)
            ->assertSee('<link rel="canonical" href="https://bk.example.com/pages/about">', false);

        $this->get(route('seo.sitemap'))
            ->assertOk()
            ->assertSee('<loc>https://bk.example.com/pages/about</loc>', false);
    }

    public function test_member_and_unpublished_custom_pages_are_noindex_and_excluded_from_sitemap(): void
    {
        $memberPage = $this->createPublishedPage('member-guide', [
            'title' => 'Member Guide',
            'description' => 'Accepted members only.',
            'audience' => 'member',
        ]);
        $draftPage = Page::query()->create([
            'slug' => 'draft-guide',
            'status' => Page::STATUS_DRAFT,
            'draft' => '<p>Draft</p>',
            'draft_metadata' => [
                'title' => 'Draft Guide',
                'description' => null,
                'audience' => 'public',
            ],
        ]);
        $seoService = app(SeoService::class);

        $memberMetadata = $seoService->pageMetadata($memberPage);

        $this->assertSame('noindex, nofollow', $memberMetadata->robots);
        $this->assertFalse($memberMetadata->indexable);
        $this->assertFalse($seoService->isRouteIndexable('pages.show', $memberPage->slug));
        $this->assertFalse($seoService->isRouteIndexable('pages.show', $draftPage->slug));

        $this->get(route('pages.show', ['slug' => $memberPage->slug]))
            ->assertRedirect(route('login'))
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $this->get(route('seo.sitemap'))
            ->assertOk()
            ->assertDontSee('/pages/member-guide</loc>', false)
            ->assertDontSee('/pages/draft-guide</loc>', false);
    }

    /**
     * @param  array{title: string, description: string|null, audience: string}  $metadata
     */
    private function createPublishedPage(string $slug, array $metadata): Page
    {
        return Page::query()->create([
            'slug' => $slug,
            'status' => Page::STATUS_PUBLISHED,
            'draft' => '<p>Content</p>',
            'published' => '<p>Content</p>',
            'cached_html' => '<p>Content</p>',
            'draft_metadata' => $metadata,
            'published_metadata' => $metadata,
        ]);
    }
}
