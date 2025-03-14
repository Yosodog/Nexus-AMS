<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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
        'verified_at'
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
        return $this->hasOne(Nations::class, "id", "nation_id");
    }

    public function accounts()
    {
        return $this->hasMany(Accounts::class, "nation_id", "nation_id");
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
}
