<?php

namespace App\Services\Backups;

use App\Enums\BackupDestination;
use App\Models\Setting;
use App\Services\Settings\SettingValueStore;
use App\Support\BackupSchedule;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Admin-managed backup settings. Values saved here win; anything not saved falls
 * back to the .env-driven config, so existing installs keep working unchanged.
 * Secrets are stored encrypted with APP_KEY and never returned to the browser.
 */
class BackupSettings
{
    public function __construct(private readonly SettingValueStore $store) {}

    public const DESTINATION = 'backup_destination';

    public const SCHEDULE_CRON = 'backup_schedule_cron';

    public const S3_BUCKET = 'backup_s3_bucket';

    public const S3_REGION = 'backup_s3_region';

    public const S3_ENDPOINT = 'backup_s3_endpoint';

    public const S3_PATH_STYLE = 'backup_s3_path_style';

    public const S3_FOLDER = 'backup_s3_folder';

    public const S3_ACCESS_KEY_ID = 'backup_s3_access_key_id';

    public const S3_SECRET_ACCESS_KEY = 'backup_s3_secret_access_key';

    public const ARCHIVE_PASSWORD = 'backup_archive_password';

    /** @var list<string> */
    public const SECRET_KEYS = [self::S3_ACCESS_KEY_ID, self::S3_SECRET_ACCESS_KEY, self::ARCHIVE_PASSWORD];

    /** @var list<string> */
    private const ALL_KEYS = [
        self::DESTINATION,
        self::SCHEDULE_CRON,
        self::S3_BUCKET,
        self::S3_REGION,
        self::S3_ENDPOINT,
        self::S3_PATH_STYLE,
        self::S3_FOLDER,
        self::S3_ACCESS_KEY_ID,
        self::S3_SECRET_ACCESS_KEY,
        self::ARCHIVE_PASSWORD,
    ];

    /**
     * Whether an admin has saved a backup configuration. Until then the .env config is used as-is.
     */
    public function hasStoredConfiguration(): bool
    {
        return BackupDestination::tryFrom((string) ($this->stored()[self::DESTINATION] ?? '')) !== null;
    }

    public function storedScheduleCron(): ?string
    {
        return $this->stored()[self::SCHEDULE_CRON] ?? null;
    }

    public function effective(): BackupConfiguration
    {
        $stored = $this->stored();
        $envDisk = (array) config('filesystems.disks.s3', []);

        return new BackupConfiguration(
            destination: BackupDestination::tryFrom((string) ($stored[self::DESTINATION] ?? '')) ?? $this->envDestination(),
            scheduleCron: BackupSchedule::expression(),
            s3Bucket: $stored[self::S3_BUCKET] ?? $this->filledOrNull($envDisk['bucket'] ?? null),
            s3Region: $stored[self::S3_REGION] ?? $this->filledOrNull($envDisk['region'] ?? null),
            s3Endpoint: $stored[self::S3_ENDPOINT] ?? $this->filledOrNull($envDisk['endpoint'] ?? null),
            s3PathStyle: array_key_exists(self::S3_PATH_STYLE, $stored)
                ? $stored[self::S3_PATH_STYLE] === '1'
                : (bool) ($envDisk['use_path_style_endpoint'] ?? false),
            s3Folder: $stored[self::S3_FOLDER] ?? $this->filledOrNull($envDisk['root'] ?? null),
            s3AccessKeyId: $stored[self::S3_ACCESS_KEY_ID] ?? $this->filledOrNull($envDisk['key'] ?? null),
            s3SecretAccessKey: $stored[self::S3_SECRET_ACCESS_KEY] ?? $this->filledOrNull($envDisk['secret'] ?? null),
            archivePassword: $stored[self::ARCHIVE_PASSWORD] ?? $this->filledOrNull(config('backup.backup.password')),
        );
    }

    /**
     * Where each secret currently comes from, for display only.
     *
     * @return array<string, 'admin'|'env'|'none'>
     */
    public function secretSources(): array
    {
        $stored = $this->stored();
        $effective = $this->effective();
        $effectiveValues = [
            self::S3_ACCESS_KEY_ID => $effective->s3AccessKeyId,
            self::S3_SECRET_ACCESS_KEY => $effective->s3SecretAccessKey,
            self::ARCHIVE_PASSWORD => $effective->archivePassword,
        ];

        return collect(self::SECRET_KEYS)
            ->mapWithKeys(fn (string $key): array => [
                $key => array_key_exists($key, $stored) ? 'admin' : (filled($effectiveValues[$key]) ? 'env' : 'none'),
            ])
            ->all();
    }

    /**
     * Save the non-secret settings and any newly entered secrets. Secrets left out of
     * $newSecrets keep their current stored value (or keep falling back to .env).
     *
     * @param  array<string, string>  $newSecrets  keyed by one of SECRET_KEYS
     */
    public function save(BackupConfiguration $configuration, array $newSecrets): void
    {
        $values = [
            self::DESTINATION => $configuration->destination->value,
            self::SCHEDULE_CRON => $configuration->scheduleCron,
            self::S3_BUCKET => (string) $configuration->s3Bucket,
            self::S3_REGION => (string) $configuration->s3Region,
            self::S3_ENDPOINT => (string) $configuration->s3Endpoint,
            self::S3_PATH_STYLE => $configuration->s3PathStyle ? '1' : '0',
            self::S3_FOLDER => (string) $configuration->s3Folder,
        ];

        foreach (self::SECRET_KEYS as $key) {
            if (filled($newSecrets[$key] ?? null)) {
                $values[$key] = Crypt::encryptString((string) $newSecrets[$key]);
            }
        }

        DB::transaction(function () use ($values): void {
            foreach ($values as $key => $value) {
                Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
            }
        });
    }

    /**
     * Remove every admin-saved backup setting so the .env config applies again.
     */
    public function forget(): void
    {
        Setting::query()->whereIn('key', self::ALL_KEYS)->delete();
    }

    /**
     * Saved values with blanks dropped and secrets decrypted.
     *
     * @return array<string, string>
     */
    private function stored(): array
    {
        return collect(self::ALL_KEYS)
            ->mapWithKeys(fn (string $key): array => [$key => $this->store->get($key)])
            ->reject(fn (mixed $value): bool => $value === null)
            ->map(fn (mixed $value): string => (string) $value)
            ->filter(fn (string $value, string $key): bool => $value !== '' || $key === self::S3_PATH_STYLE)
            ->map(fn (string $value, string $key): ?string => in_array($key, self::SECRET_KEYS, true) ? $this->decrypt($key, $value) : $value)
            ->filter(fn (?string $value): bool => $value !== null)
            ->all();
    }

    private function decrypt(string $key, string $value): ?string
    {
        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            Log::warning('Saved backup secret could not be decrypted; re-enter it in admin settings.', [
                'setting' => $key,
            ]);

            return null;
        }
    }

    private function envDestination(): BackupDestination
    {
        $disks = (array) config('backup.backup.destination.disks', []);
        $usesLocal = in_array('local', $disks, true);
        $usesS3 = in_array('s3', $disks, true);

        return match (true) {
            $usesLocal && $usesS3 => BackupDestination::LocalAndS3,
            $usesS3 => BackupDestination::S3,
            default => BackupDestination::Local,
        };
    }

    private function filledOrNull(mixed $value): ?string
    {
        return filled($value) ? (string) $value : null;
    }
}
