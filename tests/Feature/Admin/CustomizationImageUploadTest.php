<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CustomizationImageUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::define('manage-custom-pages', fn (): bool => true);
    }

    public function test_jodit_image_upload_returns_a_persistent_public_url(): void
    {
        Storage::fake('public');

        $response = $this->actingAs(User::factory()->admin()->verified()->create())
            ->postJson(route('admin.customization.images.store'), [
                'image' => UploadedFile::fake()->image('editor.png', 600, 400),
            ])
            ->assertCreated()
            ->assertJsonPath('success', 1);

        $path = $response->json('file.path');

        $this->assertIsString($path);
        Storage::disk('public')->assertExists($path);
        $this->assertSame(Storage::disk('public')->url($path), $response->json('file.url'));
    }

    public function test_jodit_image_upload_rejects_unsupported_image_types(): void
    {
        Storage::fake('public');

        $this->actingAs(User::factory()->admin()->verified()->create())
            ->postJson(route('admin.customization.images.store'), [
                'image' => UploadedFile::fake()->create('editor.svg', 10, 'image/svg+xml'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('image');
    }
}
