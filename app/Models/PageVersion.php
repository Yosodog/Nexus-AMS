<?php

namespace App\Models;

use App\Services\PageRenderer;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $page_id
 * @property int|null $user_id
 * @property string|null $editor_state
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $published_at
 */
class PageVersion extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_PREVIEW = 'preview';

    protected $fillable = [
        'page_id',
        'user_id',
        'editor_state',
        'status',
        'published_at',
    ];

    protected $casts = [
        'published_at' => 'datetime',
    ];

    protected function editorState(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $this->transformEditorValue($value),
            set: fn ($value) => $this->prepareEditorValueForStorage($value),
        );
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isPreview(): bool
    {
        return $this->status === self::STATUS_PREVIEW;
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
