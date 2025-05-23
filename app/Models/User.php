<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasApiTokens;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'nation_id',
        'verification_code',
        'verified_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'verification_code'
    ];

    protected $casts = [
        'last_active_at' => 'datetime',
        'disabled' => 'boolean',
    ];

    protected $with = ['roles'];

    /**
     * @param int $nation_id
     *
     * @return mixed
     */
    public static function getByNationId(int $nation_id)
    {
        return self::where("nation_id", $nation_id)
            ->firstOrFail();
    }

    /**
     * @return HasOne
     */
    public function nation()
    {
        return $this->hasOne(Nation::class, "id", "nation_id");
    }

    public function accounts()
    {
        return $this->hasMany(Account::class, "nation_id", "nation_id");
    }

    /**
     * @return bool
     */
    public function isVerified(): bool
    {
        return !is_null($this->verified_at);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'verified_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsToMany
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    /**
     * @param string $permission
     * @return bool
     */
    public function hasPermission(string $permission): bool
    {
        static $cachedPermissions = [];

        // Cache by user ID
        if (!array_key_exists($this->id, $cachedPermissions)) {
            $cachedPermissions[$this->id] = $this->roles
                ->flatMap(fn ($role) => $role->permissionEntries())
                ->unique()
                ->values()
                ->all();
        }

        return in_array($permission, $cachedPermissions[$this->id], true);
    }
}
