<?php

namespace Tests\Feature;

use App\Models\Alliance;
use App\Models\Page;
use App\Services\SeoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class SeoMetadataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configurePublicSite();
    }

    public function test_homepage_derives_tenant_metadata_without_persisting_defaults(): void
    {
        $alliance = $this->createPrimaryAlliance();

        $response = $this->get(route('home'));

        $response
            ->assertOk()
            ->assertHeaderMissing('X-Robots-Tag')
            ->assertSee('<title>Black Knights (BK) — Politics &amp; War Alliance | BK Net</title>', false)
            ->assertSee('<meta name="robots" content="index, follow">', false)
            ->assertSee('<link rel="canonical" href="https://bk.example.com">', false)
            ->assertSee('<meta property="og:site_name" content="BK Net">', false)
            ->assertSee('"@type":"WebSite"', false)
            ->assertSee('"@type":"Organization"', false)
            ->assertSee('politicsandwar.com', false)
            ->assertSee('id='.$alliance->id, false);

        $this->assertDatabaseMissing('settings', ['key' => 'seo_configuration']);
        $this->assertDatabaseMissing('settings', ['key' => 'home_tagline']);
    }

    public function test_derived_identity_tracks_alliance_changes_until_overridden(): void
    {
        $alliance = $this->createPrimaryAlliance();
        $seoService = app(SeoService::class);

        $this->assertSame(
            'Black Knights (BK) — Politics & War Alliance | BK Net',
            $seoService->homeMetadata($alliance, 'Alliance support and coordination.')->title,
        );

        $alliance->update(['name' => 'Knights Renamed', 'acronym' => 'KR']);

        $this->assertSame(
            'Knights Renamed (KR) — Politics & War Alliance | BK Net',
            $seoService->homeMetadata($alliance->fresh(), 'Alliance support and coordination.')->title,
        );

        $seoService->saveConfiguration([
            'indexing_enabled' => true,
            'site_name_override' => 'Knight Portal',
            'alliance_name_override' => 'Black Knights',
            'alliance_acronym_override' => 'BK',
            'home_title_override' => 'Custom Black Knights Home',
        ]);

        $alliance->update(['name' => 'Another Name', 'acronym' => 'AN']);
        $metadata = $seoService->homeMetadata($alliance->fresh(), 'Alliance support and coordination.');

        $this->assertSame('Custom Black Knights Home', $metadata->title);
        $this->assertSame('Knight Portal', $metadata->siteName);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Knight Portal home', false)
            ->assertSee('Black Knights', false)
            ->assertDontSee('Another Name', false);
    }

    public function test_duplicate_branding_and_missing_alliance_use_clean_fallback_titles(): void
    {
        config()->set('app.name', 'Black Knights');
        config()->set('services.pw.alliance_id', 9999);

        $missingAllianceMetadata = app(SeoService::class)->homeMetadata(null, '');

        $this->assertSame('Black Knights — Politics & War Alliance', $missingAllianceMetadata->title);

        $alliance = Alliance::factory()->create([
            'id' => 9999,
            'name' => 'Black Knights',
            'acronym' => 'BK',
        ]);
        $metadata = app(SeoService::class)->homeMetadata($alliance, '');

        $this->assertSame('Black Knights (BK) — Politics & War Alliance', $metadata->title);
    }

    public function test_published_application_page_and_discovery_endpoints_are_indexable(): void
    {
        $this->createPrimaryAlliance();
        Page::query()->create([
            'slug' => 'apply',
            'status' => Page::STATUS_PUBLISHED,
            'published' => '<p>Applicants should have ten cities and join Discord.</p>',
            'cached_html' => '<p>Applicants should have ten cities and join Discord.</p>',
        ]);

        $this->get(route('apply.show'))
            ->assertOk()
            ->assertHeaderMissing('X-Robots-Tag')
            ->assertSee('<title>Apply to Black Knights (BK) | BK Net</title>', false)
            ->assertSee('<meta name="robots" content="index, follow">', false);

        $this->get(route('seo.robots'))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSee('Sitemap: https://bk.example.com/sitemap.xml', false);

        $this->get(route('seo.sitemap'))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->assertSee('<loc>https://bk.example.com</loc>', false)
            ->assertSee('<loc>https://bk.example.com/apply</loc>', false)
            ->assertDontSee('<lastmod>', false);
    }

    public function test_unpublished_application_and_disabled_installations_stay_out_of_search(): void
    {
        $this->createPrimaryAlliance();

        $this->get(route('apply.show'))
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false);

        $this->get(route('seo.sitemap'))
            ->assertOk()
            ->assertDontSee('/apply</loc>', false);

        config()->set('seo.indexing_enabled', false);

        $this->get(route('home'))
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false);

        $this->get(route('seo.sitemap'))->assertNotFound();
        $this->get(route('seo.robots'))
            ->assertOk()
            ->assertDontSee('Sitemap:', false);
    }

    public function test_admin_toggle_and_non_public_app_url_block_indexing(): void
    {
        $this->createPrimaryAlliance();
        $seoService = app(SeoService::class);
        $seoService->saveConfiguration(['indexing_enabled' => false]);

        $this->get(route('home'))
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $seoService->saveConfiguration(['indexing_enabled' => true]);
        config()->set('app.url', 'http://localhost');
        URL::useOrigin('http://localhost');
        URL::forceScheme('http');

        $this->get(route('home'))
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $this->get(route('seo.sitemap'))->assertNotFound();
    }

    public function test_authentication_and_unknown_pages_receive_noindex_headers(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false);

        $this->get('https://bk.example.com/not-a-real-page')
            ->assertNotFound()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_metadata_escapes_hostile_identity_and_omits_unsafe_urls(): void
    {
        config()->set('services.pw.alliance_id', 321);
        Alliance::factory()->create([
            'id' => 321,
            'name' => '</title><script>alert("seo")</script>',
            'acronym' => 'BAD',
            'flag' => 'javascript:alert(1)',
            'wiki_link' => 'javascript:alert(2)',
            'forum_link' => 'https://forum.example.com',
            'discord_link' => 'https://discord.gg/example',
        ]);

        $response = $this->get(route('home'));

        $response
            ->assertOk()
            ->assertDontSee('</title><script>alert("seo")</script>', false)
            ->assertDontSee('javascript:alert', false)
            ->assertSee('https://forum.example.com', false);
    }

    private function configurePublicSite(): void
    {
        config()->set('app.name', 'BK Net');
        config()->set('app.url', 'https://bk.example.com');
        config()->set('seo.indexing_enabled', true);
        config()->set('services.pw.alliance_id', 123);
        URL::useOrigin('https://bk.example.com');
        URL::forceScheme('https');
    }

    private function createPrimaryAlliance(): Alliance
    {
        return Alliance::factory()->create([
            'id' => 123,
            'name' => 'Black Knights',
            'acronym' => 'BK',
            'flag' => 'https://cdn.example.com/black-knights.png',
            'forum_link' => 'https://forum.example.com/black-knights',
            'wiki_link' => 'https://wiki.example.com/black-knights',
            'discord_link' => 'https://discord.gg/black-knights',
        ]);
    }
}
