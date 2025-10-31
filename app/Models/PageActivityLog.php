<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $page_id
 * @property int|null $user_id
 * @property string $action
 * @property array|null $metadata
 */
class PageActivityLog extends Model
{
    public const ACTION_DRAFT_SAVED = 'draft_saved';
    public const ACTION_PUBLISHED = 'published';
    public const ACTION_RESTORED = 'restored';
    public const ACTION_PREVIEWED = 'previewed';

    protected $fillable = [
        'page_id',
        'user_id',
        'action',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
