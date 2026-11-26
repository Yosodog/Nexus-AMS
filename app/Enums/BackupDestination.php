<?php

namespace App\Enums;

enum BackupDestination: string
{
    case Local = 'local';
    case S3 = 's3';
    case LocalAndS3 = 'local_s3';

    public function usesS3(): bool
    {
        return $this !== self::Local;
    }

    public function usesLocal(): bool
    {
        return $this !== self::S3;
    }

    public function label(): string
    {
        return match ($this) {
            self::Local => 'This server only',
            self::S3 => 'S3 bucket',
            self::LocalAndS3 => 'This server and S3 bucket',
        };
    }
}
