<?php

namespace Tests\Feature\Security;

use App\Models\Alliance;
use App\Models\Offshore;
use App\Models\OffshoreGuardrail;
use App\Models\Page;
use App\Models\RecruitmentMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ViewErrorBag;
use Tests\FeatureTestCase;

class XssBreakoutTest extends FeatureTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_customization_editor_does_not_allow_textarea_breakout(): void
    {
        $admin = User::factory()->admin()->create();
        Gate::define('manage-custom-pages', fn (): bool => true);

        $page = Page::query()->create([
            'slug' => 'test-page',
            'status' => Page::STATUS_DRAFT,
        ]);

        // We use a whitelisted tag that survives PageRenderer but must be escaped by Blade inside textarea
        \DB::table('pages')->where('id', $page->id)->update([
            'draft' => '<b>Test</b>',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.customization.edit', $page))
            ->assertOk()
            ->assertSee(e('<b>Test</b>'), false)
            ->assertDontSee('<b>Test</b>', false);
    }

    public function test_customization_drafts_store_sanitized_html_server_side(): void
    {
        $admin = User::factory()->admin()->create();
        Gate::define('manage-custom-pages', fn (): bool => true);

        $page = Page::query()->create([
            'slug' => 'test-page',
            'status' => Page::STATUS_DRAFT,
        ]);

        $payload = <<<'HTML'
            <p onclick="alert(1)">Safe</p>
            <script>alert("xss")</script>
            <img src="javascript:alert(1)" onerror="alert(1)">
        HTML;

        $this->actingAs($admin)
            ->postJson(route('admin.customization.draft', $page), [
                'content' => $payload,
            ])
            ->assertOk()
            ->assertJsonPath('version.status', 'draft');

        $storedDraft = (string) DB::table('pages')->where('id', $page->id)->value('draft');
        $storedVersion = (string) DB::table('page_versions')->where('page_id', $page->id)->value('editor_state');

        foreach ([$storedDraft, $storedVersion] as $storedHtml) {
            $this->assertStringContainsString('<p>Safe</p>', $storedHtml);
            $this->assertStringNotContainsString('<script', $storedHtml);
            $this->assertStringNotContainsString('onclick=', $storedHtml);
            $this->assertStringNotContainsString('onerror=', $storedHtml);
            $this->assertStringNotContainsString('javascript:', $storedHtml);
        }
    }

    public function test_recruitment_settings_do_not_allow_textarea_breakout(): void
    {
        $admin = User::factory()->admin()->create();
        Gate::define('view-recruitment', fn (): bool => true);

        RecruitmentMessage::query()->updateOrCreate(
            ['type' => 'primary'],
            ['message' => '</textarea><script>alert("primary")</script>']
        );

        RecruitmentMessage::query()->updateOrCreate(
            ['type' => 'follow_up'],
            ['message' => '</textarea><script>alert("followup")</script>']
        );

        $this->actingAs($admin)
            ->get(route('admin.recruitment.index'))
            ->assertOk()
            ->assertSee(e('</textarea><script>alert("primary")</script>'), false)
            ->assertSee(e('</textarea><script>alert("followup")</script>'), false)
            ->assertDontSee('</textarea><script>alert("primary")</script>', false)
            ->assertDontSee('</textarea><script>alert("followup")</script>', false);
    }

    public function test_offshore_confirmations_encode_names_for_javascript_context(): void
    {
        $admin = User::factory()->admin()->create();
        Gate::define('view-offshores', fn (): bool => true);
        Gate::define('manage-offshores', fn (): bool => true);
        view()->share('errors', new ViewErrorBag);

        $offshore = new Offshore([
            'name' => "Offshore');alert(1);//",
            'alliance_id' => 1,
            'enabled' => true,
            'priority' => 0,
        ]);
        $offshore->id = 123;
        $offshore->exists = true;
        $offshore->setRelation('guardrails', collect());

        $this->actingAs($admin);

        $html = view('admin.offshores.index', [
            'offshores' => collect([$offshore]),
            'snapshots' => [
                $offshore->id => ['balances' => [], 'cached_at' => null],
            ],
            'transfers' => collect(),
            'resources' => ['money'],
            'guardrailResources' => OffshoreGuardrail::RESOURCES,
            'mainBankSnapshot' => ['balances' => [], 'cached_at' => null],
            'showCreateModal' => false,
            'editOffshoreId' => null,
        ])
            ->render();

        $this->assertStringNotContainsString("confirm('Sweep the entire main bank into Offshore');alert(1);//", $html);
        $this->assertStringNotContainsString("confirm('Delete Offshore');alert(1);//", $html);
        $this->assertStringContainsString('\u0027);alert(1);\/\/', $html);
    }

    public function test_homepage_omits_unsafe_alliance_links(): void
    {
        config()->set('services.pw.alliance_id', 123);

        Alliance::factory()->create([
            'id' => 123,
            'discord_link' => 'javascript:alert(1)',
            'forum_link' => 'https://forums.example.test',
            'wiki_link' => 'https://wiki.example.test',
        ]);

        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee('javascript:alert(1)', false)
            ->assertSee('https://forums.example.test', false);
    }
}
