<?php

namespace App\Services\Backups;

use Aws\Exception\AwsException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\ExecutableFinder;
use Throwable;

/**
 * Checks that a backup configuration can work without running a backup: the
 * destination accepts a write, read and delete, the database dump tool exists,
 * and the temporary directory is writable.
 */
class BackupConnectionChecker
{
    /** @var list<string> */
    private const DUMP_BINARY_SEARCH_DIRECTORIES = ['/usr/bin', '/usr/local/bin', '/opt/homebrew/bin', '/usr/local/mysql/bin'];

    /** @var array<string, string> */
    private const DUMP_BINARIES = [
        'mysql' => 'mysqldump',
        'mariadb' => 'mariadb-dump',
        'pgsql' => 'pg_dump',
        'sqlite' => 'sqlite3',
    ];

    /**
     * @return list<array{check: string, passed: bool, message: string}>
     */
    public function check(BackupConfiguration $configuration): array
    {
        $results = [
            $this->checkTemporaryDirectory(),
            $this->checkDumpBinary(),
        ];

        if ($configuration->destination->usesLocal()) {
            $results[] = $this->checkLocalDisk();
        }

        if ($configuration->destination->usesS3()) {
            $results[] = $this->checkS3($configuration);
        }

        return $results;
    }

    /**
     * @param  list<array{check: string, passed: bool, message: string}>  $results
     * @return list<string>
     */
    public static function failures(array $results): array
    {
        return collect($results)
            ->reject(fn (array $result): bool => $result['passed'])
            ->map(fn (array $result): string => $result['check'].': '.$result['message'])
            ->values()
            ->all();
    }

    /**
     * @return array{check: string, passed: bool, message: string}
     */
    private function checkS3(BackupConfiguration $configuration): array
    {
        $check = 'S3 bucket';
        $diskConfig = $configuration->s3DiskConfig() + [
            'http' => ['connect_timeout' => 5, 'timeout' => 15],
        ];
        $probePath = $this->backupFolderName().'/.nexus-connection-check-'.Str::lower(Str::random(12));

        try {
            $disk = Storage::build($diskConfig);
            $disk->put($probePath, 'nexus backup connection check');

            if ($disk->get($probePath) !== 'nexus backup connection check') {
                return $this->result($check, false, 'The test file read back different contents.');
            }

            $disk->files($this->backupFolderName());
            $disk->delete($probePath);
        } catch (Throwable $exception) {
            return $this->result($check, false, $this->describeS3Failure($exception));
        }

        return $this->result($check, true, sprintf(
            'Wrote, read, listed and deleted a test file in %s.',
            trim(implode('/', array_filter([$configuration->s3Bucket, $diskConfig['root'], $this->backupFolderName()])), '/'),
        ));
    }

    /**
     * @return array{check: string, passed: bool, message: string}
     */
    private function checkLocalDisk(): array
    {
        $root = (string) config('filesystems.disks.local.root', storage_path('app/private'));

        return $this->writableDirectoryResult('Local storage', $root);
    }

    /**
     * @return array{check: string, passed: bool, message: string}
     */
    private function checkTemporaryDirectory(): array
    {
        $directory = (string) config('backup.backup.temporary_directory', storage_path('app/backup-temp'));

        return $this->writableDirectoryResult('Temporary directory', $directory);
    }

    /**
     * @return array{check: string, passed: bool, message: string}
     */
    private function checkDumpBinary(): array
    {
        $check = 'Database dump tool';
        $connection = (string) config('database.default');
        $driver = (string) config("database.connections.{$connection}.driver", $connection);
        $binary = self::DUMP_BINARIES[$driver] ?? null;

        if ($binary === null) {
            return $this->result($check, false, "Backups do not support the {$driver} database driver.");
        }

        $configuredPath = rtrim((string) config("database.connections.{$connection}.dump.dump_binary_path", ''), '/');
        $found = $configuredPath !== ''
            ? (is_executable($configuredPath.'/'.$binary) ? $configuredPath.'/'.$binary : null)
            : (new ExecutableFinder)->find($binary, null, self::DUMP_BINARY_SEARCH_DIRECTORIES);

        return $found === null
            ? $this->result($check, false, "{$binary} was not found. Install it or set dump_binary_path for the {$connection} connection.")
            : $this->result($check, true, "Found {$found}.");
    }

    /**
     * @return array{check: string, passed: bool, message: string}
     */
    private function writableDirectoryResult(string $check, string $directory): array
    {
        if (! File::isDirectory($directory)) {
            File::ensureDirectoryExists($directory);
        }

        return File::isDirectory($directory) && File::isWritable($directory)
            ? $this->result($check, true, "{$directory} is writable.")
            : $this->result($check, false, "{$directory} is not writable by the web server.");
    }

    private function describeS3Failure(Throwable $exception): string
    {
        $aws = $exception instanceof AwsException ? $exception : $exception->getPrevious();

        if ($aws instanceof AwsException) {
            $code = (string) $aws->getAwsErrorCode();
            $message = Str::limit((string) ($aws->getAwsErrorMessage() ?: $aws->getMessage()), 300);

            return match ($code) {
                'AccessDenied' => "Access denied. Check the key's IAM policy allows this bucket and folder. AWS said: {$message}",
                'NoSuchBucket' => 'That bucket does not exist in this account and region.',
                'InvalidAccessKeyId' => 'AWS does not recognise that access key ID.',
                'SignatureDoesNotMatch' => 'The secret access key does not match the access key ID.',
                'PermanentRedirect', 'AuthorizationHeaderMalformed' => 'The bucket is in a different region. Check the region setting.',
                default => trim(($code !== '' ? $code.': ' : '').$message),
            };
        }

        return Str::limit($exception->getMessage(), 300);
    }

    private function backupFolderName(): string
    {
        return (string) config('backup.backup.name');
    }

    /**
     * @return array{check: string, passed: bool, message: string}
     */
    private function result(string $check, bool $passed, string $message): array
    {
        return ['check' => $check, 'passed' => $passed, 'message' => $message];
    }
}
