<?php

namespace App\Models;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * @property int $id
 * @property string $slug
 * @property string $status
 * @property array|null $draft
 * @property array|null $published
 * @property string|null $cached_html
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
    ];

    protected $casts = [
        'draft' => 'array',
        'published' => 'array',
    ];

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

    public function saveDraft(Arrayable|array $blocks, ?User $user = null, array $metadata = []): PageVersion
    {
        $payload = $blocks instanceof Arrayable ? $blocks->toArray() : $blocks;

        $this->draft = $payload;
        $this->status = self::STATUS_DRAFT;
        $this->save();

        $version = $this->versions()->create([
            'editor_state' => $payload,
            'status' => PageVersion::STATUS_DRAFT,
            'user_id' => $user?->id,
        ]);

        $this->recordActivity(PageActivityLog::ACTION_DRAFT_SAVED, $user, array_merge($metadata, [
            'version_id' => $version->id,
        ]));

        $this->forgetCachedHtml();

        return $version;
    }

    public function publish(Arrayable|array $blocks, string $renderedHtml, ?User $user = null, ?CarbonInterface $publishedAt = null): PageVersion
    {
        $payload = $blocks instanceof Arrayable ? $blocks->toArray() : $blocks;
        $publishedAt ??= now();

        $this->fill([
            'published' => $payload,
            'draft' => $payload,
            'status' => self::STATUS_PUBLISHED,
            'cached_html' => $renderedHtml,
        ])->save();

        $version = $this->versions()->create([
            'editor_state' => $payload,
            'status' => PageVersion::STATUS_PUBLISHED,
            'user_id' => $user?->id,
            'published_at' => $publishedAt,
        ]);

        $this->recordActivity(PageActivityLog::ACTION_PUBLISHED, $user, [
            'version_id' => $version->id,
        ]);

        $this->cachePublishedHtml($renderedHtml);

        return $version;
    }

    public function restoreFromVersion(PageVersion $version, ?User $user = null, bool $restoreAsDraft = true): void
    {
        if ($version->page_id !== $this->id) {
            throw new InvalidArgumentException('Version does not belong to the provided page.');
        }

        $this->draft = $version->editor_state;

        if (! $restoreAsDraft) {
            $this->published = $version->editor_state;
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
}
