<?php

namespace Tests\Feature;

use App\Http\Middleware\DiscordVerifiedMiddleware;
use App\Http\Middleware\EnsureMfaConfigured;
use App\Http\Middleware\EnsureUserIsVerified;
use App\Models\Alliance;
use App\Models\Nation;
use App\Models\Offshore;
use App\Models\Page;
use App\Services\AllianceMembershipService;
use App\Services\PageRenderer;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;

class CustomPageAccessTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.pw.alliance_id', 777);
        Cache::flush();
        app(AllianceMembershipService::class)->clear();
    }

    public function test_public_pages_require_published_content_and_render_sanitized_html(): void
    {
        $page = $this->page('rules', 'public');
        $page->saveDraft('<p>Draft only</p>');

        $this->get(route('pages.show', ['slug' => 'rules']))->assertNotFound();

        $page->publish(
            '<p>Alliance rules</p><script>alert(1)</script>',
            app(PageRenderer::class)->render('<p>Alliance rules</p><script>alert(1)</script>'),
        );

        $this->get(route('pages.show', ['slug' => 'rules']))
            ->assertOk()
            ->assertSee('Alliance rules')
            ->assertDontSee('alert(1)');

        $page->saveDraft('<p>Changed draft</p>');

        $this->get(route('pages.show', ['slug' => 'rules']))
            ->assertOk()
            ->assertSee('Alliance rules')
            ->assertDontSee('Changed draft');

        $page->unpublish();

        $this->get(route('pages.show', ['slug' => 'rules']))->assertNotFound();
    }

    public function test_member_pages_require_an_accepted_alliance_member(): void
    {
        $this->page('handbook', 'member')->publish('<p>Private handbook</p>', '<p>Private handbook</p>');
        $url = route('pages.show', ['slug' => 'handbook']);

        $this->get($url)->assertRedirect(route('login'));

        foreach ([[777, 'APPLICANT'], [999, 'MEMBER']] as [$allianceId, $position]) {
            $nation = Nation::factory()->create(['alliance_id' => $allianceId, 'alliance_position' => $position]);
            $user = $this->createVerifiedUser(['nation_id' => $nation->id]);

            $this->actingAs($user)
                ->withoutMiddleware([EnsureUserIsVerified::class, DiscordVerifiedMiddleware::class, EnsureMfaConfigured::class])
                ->get($url)
                ->assertForbidden();
        }

        $nation = Nation::factory()->create(['alliance_id' => 777, 'alliance_position' => 'MEMBER']);
        $user = $this->createVerifiedUser(['nation_id' => $nation->id]);

        $this->actingAs($user)
            ->withoutMiddleware([EnsureUserIsVerified::class, DiscordVerifiedMiddleware::class, EnsureMfaConfigured::class])
            ->get($url)
            ->assertOk()
            ->assertSee('Private handbook');
    }

    public function test_accepted_offshore_members_can_view_member_pages(): void
    {
        Alliance::factory()->create(['id' => 888]);
        Offshore::query()->create(['name' => 'Offshore', 'alliance_id' => 888, 'enabled' => true]);
        app(AllianceMembershipService::class)->clear();
        $this->page('handbook', 'member')->publish('<p>Private handbook</p>', '<p>Private handbook</p>');
        $nation = Nation::factory()->create(['alliance_id' => 888, 'alliance_position' => 'MEMBER']);
        $user = $this->createVerifiedUser(['nation_id' => $nation->id]);

        $this->actingAs($user)
            ->withoutMiddleware([EnsureUserIsVerified::class, DiscordVerifiedMiddleware::class, EnsureMfaConfigured::class])
            ->get(route('pages.show', ['slug' => 'handbook']))
            ->assertOk();
    }

    public function test_member_pages_enforce_account_discord_and_mfa_requirements(): void
    {
        $this->page('handbook', 'member')->publish('<p>Private handbook</p>', '<p>Private handbook</p>');
        $nation = Nation::factory()->create(['alliance_id' => 777, 'alliance_position' => 'MEMBER']);
        $user = $this->createVerifiedUser(['nation_id' => $nation->id]);
        $url = route('pages.show', ['slug' => 'handbook']);

        $user->forceFill(['verified_at' => null])->save();
        $this->actingAs($user)->get($url)->assertRedirect('/notverified');

        $user->forceFill(['verified_at' => now()])->save();
        SettingService::setDiscordVerificationRequired(true);
        $this->actingAs($user)->get($url)->assertRedirect(route('discord.verify.show'));

        $this->attachDiscordAccount($user);
        SettingService::setMfaRequiredForAllUsers(true);
        $this->actingAs($user)->get($url)->assertRedirect(route('user.settings'));

        $this->enableTwoFactor($user);
        $this->actingAs($user)->get($url)->assertOk();
    }

    public function test_audience_changes_take_effect_only_when_published(): void
    {
        $page = $this->page('guidance', 'member');
        $page->publish('<p>Guidance</p>', '<p>Guidance</p>');
        $url = route('pages.show', ['slug' => 'guidance']);

        $publicMetadata = ['title' => 'Guidance', 'description' => null, 'audience' => 'public'];
        $page->saveDraft('<p>New guidance</p>', null, [], $publicMetadata);

        $this->get($url)->assertRedirect(route('login'));

        $page->publish('<p>New guidance</p>', '<p>New guidance</p>', null, null, $publicMetadata);

        $this->get($url)->assertOk()->assertSee('New guidance');

        $memberMetadata = ['title' => 'Guidance', 'description' => null, 'audience' => 'member'];
        $page->saveDraft('<p>Private again</p>', null, [], $memberMetadata);

        $this->get($url)->assertOk()->assertSee('New guidance');

        $page->publish('<p>Private again</p>', '<p>Private again</p>', null, null, $memberMetadata);

        $this->get($url)->assertRedirect(route('login'));
    }

    private function page(string $slug, string $audience): Page
    {
        return Page::query()->create([
            'slug' => $slug,
            'status' => Page::STATUS_DRAFT,
            'draft_metadata' => [
                'title' => 'Page title',
                'description' => 'Page description',
                'audience' => $audience,
            ],
        ]);
    }
}
