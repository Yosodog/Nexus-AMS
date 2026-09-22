<?php

namespace Tests\Feature\Security;

use App\Models\Page;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustomizationPreviewSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Gate::define('manage-custom-pages', fn (): bool => true);
    }

    public function test_preview_renders_without_persisting_versions_activity_or_page_changes(): void
    {
        $page = $this->createPage();
        $page->cachePublishedHtml('existing cached HTML');

        $response = $this->actingAs($this->createAdmin())
            ->postJson(route('admin.customization.preview', $page), [
                'content' => '<p onclick="alert(1)">Safe preview</p><script>alert(1)</script>',
                'metadata' => ['origin' => 'security-test'],
            ])
            ->assertOk()
            ->assertJsonPath('html', '<p>Safe preview</p>')
            ->assertJsonMissingPath('version');

        $document = $response->json('document');
        $this->assertIsString($document);
        $this->assertStringContainsString('data-surface="public"', $document);
        $this->assertStringContainsString('<p>Safe preview</p>', $document);
        $this->assertStringNotContainsString('onclick="alert(1)"', $document);

        $this->assertDatabaseCount('page_versions', 0);
        $this->assertDatabaseCount('page_activity_logs', 0);
        $this->assertSame('existing cached HTML', Cache::get($page->cacheKey()));
        $this->assertNull($page->fresh()->draft);
    }

    public function test_preview_uses_the_selected_member_layout_without_publishing(): void
    {
        $page = $this->createPage();

        $response = $this->actingAs($this->createAdmin())
            ->postJson(route('admin.customization.preview', $page), [
                'content' => '<p>Member draft</p>',
                'page_metadata' => [
                    'title' => 'Member handbook',
                    'description' => null,
                    'audience' => 'member',
                ],
            ])
            ->assertOk();

        $document = $response->json('document');
        $this->assertIsString($document);
        $this->assertStringContainsString('data-surface="member"', $document);
        $this->assertStringContainsString('Member handbook', $document);
        $this->assertStringContainsString('<p>Member draft</p>', $document);
        $this->assertNull($page->fresh()->published);
    }

    public function test_apply_preview_uses_the_recruitment_page_layout_without_tracking_a_click(): void
    {
        $page = Page::query()->create(['slug' => 'apply', 'status' => Page::STATUS_DRAFT]);

        $response = $this->actingAs($this->createAdmin())
            ->postJson(route('admin.customization.preview', $page), [
                'content' => '<p>Draft recruitment requirements</p>',
            ])
            ->assertOk();

        $document = $response->json('document');
        $this->assertIsString($document);
        $this->assertStringContainsString('data-surface="public"', $document);
        $this->assertStringContainsString('How to submit your application', $document);
        $this->assertStringContainsString('<p>Draft recruitment requirements</p>', $document);
        $this->assertNull($page->fresh()->published);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('invalidPayloadProvider')]
    public function test_preview_rejects_payloads_outside_resource_budgets(array $payload, string $errorField): void
    {
        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.customization.preview', $this->createPage()), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($errorField);

        $this->assertDatabaseCount('page_versions', 0);
        $this->assertDatabaseCount('page_activity_logs', 0);
    }

    public function test_preview_route_is_rate_limited_per_user(): void
    {
        $page = $this->createPage();
        $this->actingAs($this->createAdmin());

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->postJson(route('admin.customization.preview', $page), [
                'content' => '<p>Preview '.$attempt.'</p>',
            ])->assertOk();
        }

        $this->postJson(route('admin.customization.preview', $page), [
            'content' => '<p>One too many</p>',
        ])->assertTooManyRequests();

        $this->assertDatabaseCount('page_versions', 0);
        $this->assertDatabaseCount('page_activity_logs', 0);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidPayloadProvider(): array
    {
        return [
            'content character limit' => [
                ['content' => str_repeat('a', 100_001)],
                'content',
            ],
            'block count limit' => [
                [
                    'content' => '<p>Preview</p>',
                    'metadata' => ['blocks' => array_fill(0, 101, ['type' => 'paragraph'])],
                ],
                'metadata',
            ],
            'nesting depth limit' => [
                [
                    'content' => '<p>Preview</p>',
                    'metadata' => ['a' => ['b' => ['c' => ['d' => ['e' => ['f' => ['g' => true]]]]]]],
                ],
                'metadata',
            ],
            'nested string limit' => [
                [
                    'content' => '<p>Preview</p>',
                    'metadata' => ['label' => str_repeat('a', 2_001)],
                ],
                'metadata',
            ],
            'serialized byte limit' => [
                ['content' => str_repeat('💣', 40_000)],
                'content',
            ],
        ];
    }

    private function createPage(): Page
    {
        return Page::query()->create([
            'slug' => 'preview-security-'.fake()->unique()->uuid(),
            'status' => Page::STATUS_DRAFT,
        ]);
    }

    private function createAdmin(): User
    {
        return User::factory()->admin()->verified()->create();
    }
}
