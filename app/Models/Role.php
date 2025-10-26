<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class Role extends Model
{
    protected $fillable = ['name', 'protected'];
    protected $casts = [
        'protected' => 'boolean',
    ];

    /**
     * @return BelongsToMany
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    /**
     * @return HasMany
     */
    public function permissions(): HasMany
    {
        return $this->hasMany(RolePermission::class)->orderBy('permission');
    }

    /**
     * @return bool
     * @throws \Exception
     */
    public function delete(): bool
    {
        if ($this->protected) {
            throw new \Exception("Cannot delete protected role: {$this->name}");
        }

        return parent::delete();
    }

    /**
     * @return Collection
     */
    public function permissionEntries(): \Illuminate\Support\Collection
    {
        return collect(
            DB::table('role_permissions')
                ->where('role_id', $this->id)
                ->pluck('permission')
        );
    }
}
