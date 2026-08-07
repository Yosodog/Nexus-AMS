<?php

namespace App\Exceptions;

use LogicException;

final class WorldWriteForbidden extends LogicException
{
    public const ERROR_CODE = 'runtime.world_write_forbidden';

    public function __construct()
    {
        parent::__construct('Public world data is read-only in hosted tenant mode.');
    }

    public function errorCode(): string
    {
        return self::ERROR_CODE;
    }
}
