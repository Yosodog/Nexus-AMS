<?php

namespace App\Exceptions;

final class UpdaterSubmissionUnknownException extends UpdaterException
{
    public function __construct(
        public readonly string $operationId,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            "The updater did not confirm whether the operation was accepted. Check operation {$operationId} before retrying.",
            'submission_status_unknown',
            $previous,
        );
    }
}
