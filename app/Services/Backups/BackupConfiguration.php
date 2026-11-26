<?php

namespace App\Services\Backups;

use App\Enums\BackupDestination;

/**
 * Where and when backups run, after admin settings and .env fallbacks are merged.
 */
final readonly class BackupConfiguration
{
    public const S3_DISK = 'nexus-backup-s3';

    public function __construct(
        public BackupDestination $destination,
        public string $scheduleCron,
        public ?string $s3Bucket = null,
        public ?string $s3Region = null,
        public ?string $s3Endpoint = null,
        public bool $s3PathStyle = false,
        public ?string $s3Folder = null,
        public ?string $s3AccessKeyId = null,
        public ?string $s3SecretAccessKey = null,
        public ?string $archivePassword = null,
    ) {}

    /**
     * @return list<string>
     */
    public function diskNames(): array
    {
        return array_values(array_filter([
            $this->destination->usesLocal() ? 'local' : null,
            $this->destination->usesS3() ? self::S3_DISK : null,
        ]));
    }

    /**
     * @return array{driver: string, key: ?string, secret: ?string, region: ?string, bucket: ?string, endpoint: ?string, use_path_style_endpoint: bool, root: string, throw: bool}
     */
    public function s3DiskConfig(): array
    {
        return [
            'driver' => 's3',
            'key' => $this->s3AccessKeyId,
            'secret' => $this->s3SecretAccessKey,
            'region' => $this->s3Region,
            'bucket' => $this->s3Bucket,
            'endpoint' => $this->s3Endpoint,
            'use_path_style_endpoint' => $this->s3PathStyle,
            'root' => trim((string) $this->s3Folder, '/'),
            'throw' => true,
        ];
    }

    public function hasArchivePassword(): bool
    {
        return filled($this->archivePassword);
    }
}
