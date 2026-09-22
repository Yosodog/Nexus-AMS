<?php

namespace App\Models;

use App\Services\PageRenderer;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * @property int $id
 * @property string $slug
 * @property string $status
 * @property string|null $draft
 * @property string|null $published
 * @property string|null $cached_html
 * @property array<string, string>|null $draft_metadata
 * @property array<string, string>|null $published_metadata
 */
class Page extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    private const CACHE_TTL_MINUTES = 5;

    protected $fillable = [
        'slug',
        'status',
        'draft',
        'published',
        'cached_html',
        'draft_metadata',
        'published_metadata',
    ];

    protected $casts = [
        'cached_html' => 'string',
        'draft_metadata' => 'array',
        'published_metadata' => 'array',
    ];

    protected function draft(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $this->transformEditorValue($value),
            set: fn ($value) => $this->prepareEditorValueForStorage($value),
        );
    }

    protected function published(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $this->transformEditorValue($value),
            set: fn ($value) => $this->prepareEditorValueForStorage($value),
        );
    }

    public function versions(): HasMany
    {
        return $this->hasMany(PageVersion::class);
    }

    public function latestPublishedVersion(): HasOne
    {
        return $this
            ->hasOne(PageVersion::class)
            ->latestOfMany('published_at')
            ->where('status', PageVersion::STATUS_PUBLISHED);
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(PageActivityLog::class);
    }

    public function saveDraft(string $content, ?User $user = null, array $metadata = [], ?array $pageMetadata = null): PageVersion
    {
        $this->draft = $content;
        if ($pageMetadata !== null) {
            $this->draft_metadata = $pageMetadata;
        }
        $this->status = self::STATUS_DRAFT;
        $this->save();

        $version = $this->versions()->create([
            'editor_state' => $content,
            'status' => PageVersion::STATUS_DRAFT,
            'user_id' => $user?->id,
            'page_metadata' => $this->draft_metadata,
        ]);

        $this->recordActivity(PageActivityLog::ACTION_DRAFT_SAVED, $user, array_merge($metadata, [
            'version_id' => $version->id,
        ]));

        $this->forgetCachedHtml();

        return $version;
    }

    public function publish(string $content, string $renderedHtml, ?User $user = null, ?CarbonInterface $publishedAt = null, ?array $pageMetadata = null): PageVersion
    {
        $publishedAt ??= now();

        if ($pageMetadata !== null) {
            $this->draft_metadata = $pageMetadata;
        }

        $this->fill([
            'published' => $content,
            'draft' => $content,
            'status' => self::STATUS_PUBLISHED,
            'cached_html' => $renderedHtml,
            'published_metadata' => $this->draft_metadata,
        ])->save();

        $version = $this->versions()->create([
            'editor_state' => $content,
            'status' => PageVersion::STATUS_PUBLISHED,
            'user_id' => $user?->id,
            'published_at' => $publishedAt,
            'page_metadata' => $this->published_metadata,
        ]);

        $this->recordActivity(PageActivityLog::ACTION_PUBLISHED, $user, [
            'version_id' => $version->id,
        ]);

        $this->cachePublishedHtml($renderedHtml);

        return $version;
    }

    public function unpublish(?User $user = null): void
    {
        $this->forceFill([
            'published' => null,
            'published_metadata' => null,
            'cached_html' => null,
            'status' => self::STATUS_DRAFT,
        ])->save();

        $this->recordActivity(PageActivityLog::ACTION_UNPUBLISHED, $user);
        $this->forgetCachedHtml();
    }

    public function hasPublishedContent(): bool
    {
        return is_string($this->published) && trim($this->published) !== '';
    }

    public function restoreFromVersion(PageVersion $version, ?User $user = null, bool $restoreAsDraft = true, ?string $content = null): void
    {
        if ($version->page_id !== $this->id) {
            throw new InvalidArgumentException('Version does not belong to the provided page.');
        }

        $normalized = $content ?? (string) $version->editor_state;

        $this->draft = $normalized;

        if ($version->page_metadata !== null) {
            $this->draft_metadata = $version->page_metadata;
        }

        if (! $restoreAsDraft) {
            $this->published = $normalized;
            $this->published_metadata = $this->draft_metadata;
            $this->status = self::STATUS_PUBLISHED;
            $this->cached_html = null;
        } else {
            $this->status = self::STATUS_DRAFT;
        }

        $this->save();

        $this->recordActivity(PageActivityLog::ACTION_RESTORED, $user, [
            'version_id' => $version->id,
            'restore_as_draft' => $restoreAsDraft,
        ]);

        $this->forgetCachedHtml();
    }

    /**
     * @return \Illuminate\Support\Collection<int, mixed>
     */
    public function snapshots(int $limit = 50): Collection
    {
        return $this->versions()
            ->latest('created_at')
            ->limit($limit)
            ->get();
    }

    public function cacheKey(string $suffix = 'html'): string
    {
        return sprintf('pages:%s:%s', $this->slug, $suffix);
    }

    public function cachePublishedHtml(string $renderedHtml): void
    {
        Cache::put($this->cacheKey(), $renderedHtml, now()->addMinutes(self::CACHE_TTL_MINUTES));
    }

    public function forgetCachedHtml(): void
    {
        Cache::forget($this->cacheKey());
    }

    protected function recordActivity(string $action, ?User $user = null, array $metadata = []): void
    {
        $this->activityLogs()->create([
            'action' => $action,
            'user_id' => $user?->id,
            'metadata' => $metadata,
        ]);
    }

    private function transformEditorValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                return $this->normalizeDecodedEditorValue($decoded);
            }

            return $value;
        }

        return $this->normalizeDecodedEditorValue($value);
    }

    private function normalizeDecodedEditorValue(mixed $value): ?string
    {
        if (is_array($value) || is_string($value)) {
            return app(PageRenderer::class)->render($value);
        }

        if (is_object($value)) {
            $encoded = json_encode($value);

            if (is_string($encoded)) {
                $decoded = json_decode($encoded, true);

                if (json_last_error() === JSON_ERROR_NONE) {
                    return $this->normalizeDecodedEditorValue($decoded);
                }
            }
        }

        return is_scalar($value) ? (string) $value : null;
    }

    private function prepareEditorValueForStorage(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return json_encode(['html' => $value], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return json_encode((string) $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
