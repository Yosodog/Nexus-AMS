<?php

declare(strict_types=1);

namespace App\Services\TenantControl;

use App\Exceptions\TenantControlConfigurationException;

final class TenantControlKey
{
    public function value(): string
    {
        $path = config('nexus.control.callback_key_file');

        if (! is_string($path) || $path === '' || strlen($path) > 1_024 || ! is_readable($path)) {
            throw new TenantControlConfigurationException;
        }

        clearstatcache(true, $path);
        $size = @filesize($path);

        if (! is_int($size) || $size < 32 || $size > 4_096) {
            throw new TenantControlConfigurationException;
        }

        $value = @file_get_contents($path, false, null, 0, 4_097);
        $value = is_string($value) ? trim($value) : '';

        if (strlen($value) < 32 || strlen($value) > 512) {
            throw new TenantControlConfigurationException;
        }

        return $value;
    }
}
