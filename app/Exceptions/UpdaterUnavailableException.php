<?php

namespace App\Exceptions;

final class UpdaterUnavailableException extends UpdaterException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct(
            'The Nexus updater is unavailable. Use the nexus CLI on the host to inspect the installation.',
            'updater_unavailable',
            $previous,
        );
    }
}
