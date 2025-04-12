<?php

namespace App\Models;

use App\Services\PWHelperService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Account extends Model
{
    use SoftDeletes;

    public $table = "accounts";

    protected array $resources;

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->resources = PWHelperService::resources();
    }

    /**
     * @return BelongsTo
     */
    public function nation()
    {
        return $this->belongsTo(Nations::class, "nation_id", "id");
    }

    /**
     * @return BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class, "nation_id", "nation_id");
    }

    /**
     * @return HasMany
     */
    public function cityGrants()
    {
        return $this->hasMany(CityGrantRequest::class, "account_id");
    }

    /**
     * @return HasMany
     */
    public function loans()
    {
        return $this->hasMany(Loans::class, "account_id");
    }

    /**
     * Checks if the account is completely empty.
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        foreach ($this->resources as $res) {
            if ($this->$res != 0) {
                return false;
            }
        }

        return true;
    }
}
