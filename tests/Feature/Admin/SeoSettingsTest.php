<?php

namespace Tests\Feature\Admin;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;

class SeoSettingsTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.name', 'BK Net');
        config()->set('app.url', 'https://bk.example.com');
        config()->set('seo.indexing_enabled', true);
        URL::useOrigin('https://bk.example.com');
        URL::forceScheme('https');
    }

    public function test_diagnostic_admin_can_view_automatic_values_without_persisting_seo_defaults(): void
    {
        $admin = $this->createAdmin(['view-diagnostic-info']);

        $this->assertDatabaseMissing('settings', ['key' => 'seo_configuration']);

        $this->actingAs($admin)
            ->get(route('admin.settings'))
            ->assertOk()
            ->assertSee('Search &amp; Sharing', false)
            ->assertSee('Leave a field blank to keep deriving it', false);

        $this->assertDatabaseMissing('settings', ['key' => 'seo_configuration']);
    }

    public function test_seo_settings_are_trimmed_persisted_and_audited(): void
    {
        $admin = $this->createAdmin(['view-diagnostic-info']);

        $this->actingAs($admin)
            ->post(route('admin.settings.seo'), $this->validPayload([
                'site_name_override' => '  Knight Portal  ',
                'alliance_name_override' => '  Black Knights  ',
                'alliance_acronym_override' => '  BK  ',
                'home_title_override' => '  Custom home title  ',
                'home_description_override' => '  Custom home description.  ',
                'apply_title_override' => '',
                'apply_description_override' => '  Custom application description.  ',
            ]))
            ->assertRedirect(route('admin.settings'));

        $configuration = $this->storedConfiguration();

        $this->assertTrue($configuration['indexing_enabled']);
        $this->assertSame('Knight Portal', $configuration['site_name_override']);
        $this->assertSame('Black Knights', $configuration['alliance_name_override']);
        $this->assertSame('BK', $configuration['alliance_acronym_override']);
        $this->assertSame('Custom home title', $configuration['home_title_override']);
        $this->assertNull($configuration['apply_title_override']);
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $admin->id,
            'category' => 'settings',
            'action' => 'seo_settings_updated',
            'outcome' => 'success',
        ]);
    }

    public function test_admin_without_diagnostic_permission_cannot_update_seo_settings(): void
    {
        $admin = $this->createAdmin(['manage-accounts']);

        $this->actingAs($admin)
            ->post(route('admin.settings.seo'), $this->validPayload())
            ->assertForbidden();

        $this->assertDatabaseMissing('settings', ['key' => 'seo_configuration']);
    }

    public function test_custom_social_image_can_be_uploaded_and_removed(): void
    {
        Storage::fake('public');
        $admin = $this->createAdmin(['view-diagnostic-info']);

        $this->actingAs($admin)
            ->post(route('admin.settings.seo'), $this->validPayload([
                'social_image' => UploadedFile::fake()->image('share.png', 1200, 630),
            ]))
            ->assertRedirect(route('admin.settings'));

        $storedPath = $this->storedConfiguration()['social_image_path'];

        $this->assertIsString($storedPath);
        Storage::disk('public')->assertExists($storedPath);

        $this->actingAs($admin)
            ->post(route('admin.settings.seo'), $this->validPayload([
                'remove_social_image' => '1',
            ]))
            ->assertRedirect(route('admin.settings'));

        $this->assertNull($this->storedConfiguration()['social_image_path']);
        Storage::disk('public')->assertMissing($storedPath);
    }

    public function test_social_image_validation_rejects_svg_dimensions_and_conflicting_actions(): void
    {
        Storage::fake('public');
        $admin = $this->createAdmin(['view-diagnostic-info']);

        $this->actingAs($admin)
            ->from(route('admin.settings'))
            ->post(route('admin.settings.seo'), $this->validPayload([
                'social_image' => UploadedFile::fake()->createWithContent(
                    'share.svg',
                    '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
                ),
            ]))
            ->assertRedirect(route('admin.settings'))
            ->assertSessionHasErrors('social_image');

        $this->actingAs($admin)
            ->from(route('admin.settings'))
            ->post(route('admin.settings.seo'), $this->validPayload([
                'social_image' => UploadedFile::fake()->image('small.png', 300, 200),
            ]))
            ->assertRedirect(route('admin.settings'))
            ->assertSessionHasErrors('social_image');

        $this->actingAs($admin)
            ->from(route('admin.settings'))
            ->post(route('admin.settings.seo'), $this->validPayload([
                'social_image' => UploadedFile::fake()->image('share.png', 1200, 630),
                'remove_social_image' => '1',
            ]))
            ->assertRedirect(route('admin.settings'))
            ->assertSessionHasErrors('social_image');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'indexing_enabled' => '1',
            'site_name_override' => null,
            'alliance_name_override' => null,
            'alliance_acronym_override' => null,
            'home_title_override' => null,
            'home_description_override' => null,
            'apply_title_override' => null,
            'apply_description_override' => null,
            'remove_social_image' => '0',
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function storedConfiguration(): array
    {
        $value = Setting::query()->where('key', 'seo_configuration')->value('value');

        $this->assertIsString($value);

        return json_decode($value, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function createAdmin(array $permissions): User
    {
        $admin = $this->createVerifiedAdmin();
        $this->attachDiscordAccount($admin);

        return $this->grantPermissions($admin, $permissions);
    }
}
