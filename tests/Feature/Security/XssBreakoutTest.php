<?php

namespace Tests\Feature\Security;

use App\Models\Page;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class XssBreakoutTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that initial content in the customization editor is escaped to prevent </textarea> breakout.
     */
    public function test_customization_editor_escapes_initial_content()
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Gate::define('manage-custom-pages', fn() => true);

        $page = Page::create([
            'slug' => 'test-page',
            'status' => 'draft',
            'draft' => '</textarea><script>alert("xss")</script>',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.customization.edit', $page))
            ->assertStatus(200)
            ->assertSee('&lt;/textarea&gt;&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;', false)
            ->assertDontSee('</textarea><script>alert("xss")</script>', false);
    }

    /**
     * Test that recruitment messages are escaped in the textarea to prevent </textarea> breakout.
     */
    public function test_recruitment_settings_escapes_messages()
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Gate::define('view-recruitment', fn() => true);

        // Mock setting values
        config(['settings.recruitment_primary_message' => '</textarea><script>alert("primary")</script>']);
        config(['settings.recruitment_follow_up_message' => '</textarea><script>alert("followup")</script>']);

        $this->actingAs($admin)
            ->get(route('admin.recruitment.index'))
            ->assertStatus(200)
            ->assertSee('&lt;/textarea&gt;&lt;script&gt;alert(&quot;primary&quot;)&lt;/script&gt;', false)
            ->assertSee('&lt;/textarea&gt;&lt;script&gt;alert(&quot;followup&quot;)&lt;/script&gt;', false)
            ->assertDontSee('</textarea><script>alert("primary")</script>', false)
            ->assertDontSee('</textarea><script>alert("followup")</script>', false);
    }
}
