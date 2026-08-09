<?php

namespace Database\Factories;

use App\Models\FederationPublication;
use App\Models\FederationPublicationVersion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FederationPublicationVersion>
 */
class FederationPublicationVersionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $preview = json_encode([
            'schema' => 'milcom.war-plan-snapshot/1.0',
            'version' => 1,
            'revision' => 1,
            'targets' => [],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return [
            'id' => (string) Str::ulid(),
            'federation_publication_id' => FederationPublication::factory(),
            'version' => 1,
            'revision' => 1,
            'source_generation' => 1,
            'schema_version' => '1.0',
            'recipients_hash' => hash('sha256', 'recipient-installation'),
            'preview_hash' => hash('sha256', $preview),
            'canonical_preview' => $preview,
            'status' => 'preview',
            'created_by' => null,
            'expires_at' => now()->addDays(7),
            'published_at' => null,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (): array => [
            'status' => 'published',
            'published_at' => now(),
        ]);
    }

    public function preview(): static
    {
        return $this->state(fn (): array => [
            'status' => 'preview',
            'published_at' => null,
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => [
            'status' => 'revoked',
            'published_at' => now()->subDay(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'status' => 'expired',
            'expires_at' => now()->subMinute(),
            'published_at' => now()->subDay(),
        ]);
    }

    public function version(int $version, int $revision): static
    {
        return $this->state(fn (): array => [
            'version' => $version,
            'revision' => $revision,
        ]);
    }
}
