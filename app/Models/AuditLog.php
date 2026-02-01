<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function scopeForActor(Builder $query, string $actorType, ?int $actorId = null): Builder
    {
        $query->where('actor_type', $actorType);

        if ($actorId !== null) {
            $query->where('actor_id', $actorId);
        }

        return $query;
    }

    public function scopeForSubject(Builder $query, string $subjectType, ?string $subjectId = null): Builder
    {
        $query->where('subject_type', $subjectType);

        if ($subjectId !== null) {
            $query->where('subject_id', $subjectId);
        }

        return $query;
    }
}
