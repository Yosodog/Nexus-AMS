<?php

namespace Tests\Feature\Security;

use App\Models\Page;
use App\Models\RecruitmentMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
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
            'draft' => '</textarea><script>alert("xss")</script>',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.customization.edit', $page))
            ->assertOk()
            ->assertDontSee('</textarea><script>alert("xss")</script>', false);
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
            ->assertSee('&lt;/textarea&gt;', false)
            ->assertDontSee('</textarea><script>alert("primary")</script>', false)
            ->assertDontSee('</textarea><script>alert("followup")</script>', false);
    }
}
