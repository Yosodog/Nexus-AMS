<?php

namespace App\Services\World;

use App\Exceptions\WorldWriteForbidden;
use App\Services\RuntimeCapabilities;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final readonly class WorldWriteGuard
{
    public function __construct(private RuntimeCapabilities $capabilities) {}

    public function saving(Model $model): void
    {
        $this->assertCanWrite($model);
    }

    public function deleting(Model $model): void
    {
        $this->assertCanWrite($model);
    }

    public function restoring(Model $model): void
    {
        $this->assertCanWrite($model);
    }

    /**
     * @param  Model|class-string<Model>  $model
     */
    public function assertCanWrite(Model|string $model): void
    {
        if (! WorldModelManifest::contains($model)) {
            throw new InvalidArgumentException('World write guard received an unclassified model.');
        }

        if (! $this->capabilities->writesPublicWorld()) {
            throw new WorldWriteForbidden;
        }
    }
}
