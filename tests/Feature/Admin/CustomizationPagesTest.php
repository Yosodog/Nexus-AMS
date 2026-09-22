<?php

namespace Tests\Feature\Admin;

use App\Models\Page;
use App\Models\PageVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class CustomizationPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::define('manage-custom-pages', fn (): bool => true);
    }

    public function test_admin_can_open_the_create_page_form(): void
    {
        $this->actingAs(User::factory()->admin()->verified()->create())
            ->get(route('admin.customization.create'))
            ->assertOk()
            ->assertSee('Create custom page')
            ->assertSee('Search description');
    }

    public function test_editor_opens_the_live_page_in_a_new_tab_only_when_available(): void
    {
        $admin = User::factory()->admin()->verified()->create();
        $page = Page::query()->create([
            'slug' => 'guide',
            'status' => Page::STATUS_DRAFT,
            'draft_metadata' => ['title' => 'Guide', 'description' => null, 'audience' => 'public'],
        ]);
        $url = route('pages.show', ['slug' => 'guide']);

        $draft = $this->actingAs($admin)->get(route('admin.customization.edit', $page))->assertOk();
        $draft->assertSee('href="'.$url.'" target="_blank" rel="noopener noreferrer"', false);
        $this->assertMatchesRegularExpression('/id="customization-view-live"[^>]*class="[^"]*hidden/', $draft->getContent());

        $page->publish('<p>Live</p>', '<p>Live</p>');

        $published = $this->actingAs($admin)->get(route('admin.customization.edit', $page))->assertOk();
        $this->assertMatchesRegularExpression('/id="customization-view-live"[^>]*class="[^"]*btn btn-outline btn-sm/', $published->getContent());
        $this->assertDoesNotMatchRegularExpression('/id="customization-view-live"[^>]*class="[^"]*hidden/', $published->getContent());
    }

    public function test_users_without_page_management_permission_cannot_create_pages(): void
    {
        Gate::define('manage-custom-pages', fn (): bool => false);

        $this->actingAs(User::factory()->admin()->verified()->create())
            ->get(route('admin.customization.create'))
            ->assertForbidden();
    }

    public function test_admin_can_create_a_custom_page_with_an_initial_blank_draft(): void
    {
        $admin = User::factory()->admin()->verified()->create();

        $response = $this->actingAs($admin)->post(route('admin.customization.store'), [
            'title' => 'About the alliance',
            'slug' => 'about-us',
            'description' => 'Learn about our alliance.',
            'audience' => 'public',
        ]);

        $page = Page::query()->where('slug', 'about-us')->firstOrFail();

        $response->assertRedirect(route('admin.customization.edit', $page));
        $this->assertSame(Page::STATUS_DRAFT, $page->status);
        $this->assertSame('', $page->draft);
        $this->assertSame([
            'title' => 'About the alliance',
            'description' => 'Learn about our alliance.',
            'audience' => 'public',
        ], $page->draft_metadata);
        $this->assertDatabaseHas('page_versions', [
            'page_id' => $page->id,
            'status' => 'draft',
        ]);
    }

    public function test_create_rejects_reserved_and_duplicate_slugs(): void
    {
        $admin = User::factory()->admin()->verified()->create();
        Page::query()->create([
            'slug' => 'existing-page',
            'status' => Page::STATUS_DRAFT,
        ]);

        $payload = [
            'title' => 'Page',
            'description' => '',
            'audience' => 'public',
        ];

        $this->actingAs($admin)
            ->from(route('admin.customization.create'))
            ->post(route('admin.customization.store'), [...$payload, 'slug' => 'apply'])
            ->assertSessionHasErrors('slug');

        $this->actingAs($admin)
            ->from(route('admin.customization.create'))
            ->post(route('admin.customization.store'), [...$payload, 'slug' => 'existing-page'])
            ->assertSessionHasErrors('slug');
    }

    public function test_draft_and_publish_store_page_details_together_with_content(): void
    {
        $admin = User::factory()->admin()->verified()->create();
        $page = Page::query()->create([
            'slug' => 'members-guide',
            'status' => Page::STATUS_DRAFT,
            'draft_metadata' => [
                'title' => 'Members guide',
                'description' => null,
                'audience' => 'member',
            ],
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.customization.draft', $page), [
                'content' => '<p>Draft guide</p>',
                'page_metadata' => [
                    'title' => 'Members guide',
                    'description' => 'Private guide.',
                    'audience' => 'member',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('page.page_metadata.audience', 'member');

        $page->refresh();
        $this->assertSame('Private guide.', $page->draft_metadata['description']);

        $this->actingAs($admin)
            ->postJson(route('admin.customization.publish', $page), [
                'content' => '<p>Published guide</p>',
                'page_metadata' => [
                    'title' => 'Members guide',
                    'description' => 'Private guide.',
                    'audience' => 'member',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('page.status_label', 'Live');

        $page->refresh();
        $this->assertSame('member', $page->published_metadata['audience']);
        $this->assertSame('Private guide.', $page->published_metadata['description']);
    }

    public function test_saving_new_draft_details_keeps_the_older_published_version_live(): void
    {
        $admin = User::factory()->admin()->verified()->create();
        $page = Page::query()->create([
            'slug' => 'editable-page',
            'status' => Page::STATUS_DRAFT,
            'draft_metadata' => ['title' => 'Original', 'description' => null, 'audience' => 'public'],
        ]);

        $this->actingAs($admin)->postJson(route('admin.customization.publish', $page), [
            'content' => '<p>Live content</p>',
            'page_metadata' => ['title' => 'Original', 'description' => null, 'audience' => 'public'],
        ])->assertOk();

        $this->actingAs($admin)
            ->postJson(route('admin.customization.draft', $page), [
                'content' => '<p>Unpublished edit</p>',
                'page_metadata' => ['title' => 'Updated title', 'description' => null, 'audience' => 'public'],
            ])
            ->assertOk()
            ->assertJsonPath('page.status_label', 'Live with unpublished changes')
            ->assertJsonPath('page.published_metadata.title', 'Original');
    }

    public function test_restoring_a_version_restores_its_page_details_with_content(): void
    {
        $admin = User::factory()->admin()->verified()->create();
        $page = Page::query()->create([
            'slug' => 'restorable-page',
            'status' => Page::STATUS_DRAFT,
            'draft_metadata' => ['title' => 'Original', 'description' => 'Original description', 'audience' => 'public'],
        ]);

        $this->actingAs($admin)->postJson(route('admin.customization.publish', $page), [
            'content' => '<p>Original content</p>',
            'page_metadata' => [
                'title' => 'Original',
                'description' => 'Original description',
                'audience' => 'public',
            ],
        ])->assertOk();

        $sourceVersion = $page->versions()->where('status', PageVersion::STATUS_PUBLISHED)->firstOrFail();

        $this->actingAs($admin)
            ->postJson(route('admin.customization.restore', $page), [
                'version_id' => $sourceVersion->id,
                'publish' => true,
            ])
            ->assertOk()
            ->assertJsonPath('version.page_metadata.title', 'Original')
            ->assertJsonPath('page.published_metadata.description', 'Original description');
    }

    public function test_custom_pages_can_be_unpublished_but_apply_cannot(): void
    {
        $admin = User::factory()->admin()->verified()->create();
        $page = Page::query()->create([
            'slug' => 'unpublishable-page',
            'status' => Page::STATUS_DRAFT,
            'draft_metadata' => ['title' => 'Page', 'description' => null, 'audience' => 'public'],
        ]);

        $this->actingAs($admin)->postJson(route('admin.customization.publish', $page), [
            'content' => '<p>Live</p>',
            'page_metadata' => ['title' => 'Page', 'description' => null, 'audience' => 'public'],
        ])->assertOk();

        $this->actingAs($admin)
            ->postJson(route('admin.customization.unpublish', $page))
            ->assertOk()
            ->assertJsonPath('page.status_label', 'Unpublished');

        $apply = Page::query()->create([
            'slug' => 'apply',
            'status' => Page::STATUS_PUBLISHED,
            'published' => '<p>Recruitment</p>',
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.customization.unpublish', $apply))
            ->assertStatus(422);
    }
}
