<?php

namespace App\Models;

use Database\Factories\OperationsTeamSavedViewFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperationsTeamSavedView extends Model
{
    /** @use HasFactory<OperationsTeamSavedViewFactory> */
    use HasFactory;

    protected $fillable = [
        'public_id',
        'team_key',
        'name',
        'filters',
        'created_by_user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'filters' => 'array',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
