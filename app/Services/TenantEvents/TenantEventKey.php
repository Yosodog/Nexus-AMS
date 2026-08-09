<?php

declare(strict_types=1);

namespace App\Services\TenantEvents;

use App\Exceptions\TenantEventConfigurationException;

final class TenantEventKey
{
    public function value(): string
    {
        $path = config('nexus.tenant_events.key_file');

        if (! is_string($path) || $path === '' || strlen($path) > 1_024 || ! is_readable($path)) {
            throw new TenantEventConfigurationException;
        }

        clearstatcache(true, $path);
        $size = @filesize($path);

        if (! is_int($size) || $size < 32 || $size > 4_096) {
            throw new TenantEventConfigurationException;
        }

        $value = @file_get_contents($path, false, null, 0, 4_097);
        $value = is_string($value) ? trim($value) : '';

        if (strlen($value) < 32 || strlen($value) > 512) {
            throw new TenantEventConfigurationException;
        }

        return $value;
    }
}
