<?php

declare(strict_types=1);

use App\Enums\NexusProcessRole;
use App\Services\RuntimeBuildMetadata;

try {
    $projectRoot = dirname(__DIR__, 2);
    require $projectRoot.'/vendor/autoload.php';
    $options = getopt('', ['output:', 'version:', 'commit:']);

    if (! is_array($options)) {
        throw new RuntimeException('Invalid build metadata options.');
    }

    $output = requiredOption($options, 'output');
    $version = requiredOption($options, 'version');
    $commit = requiredOption($options, 'commit');

    if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._+-]{0,63}\z/D', $version) !== 1
        || preg_match('/\A[a-f0-9]{40}\z/D', $commit) !== 1) {
        throw new RuntimeException('Invalid build identity.');
    }

    $metadata = [
        'application_version' => $version,
        'commit' => $commit,
        'php' => PHP_VERSION,
        'runtime_contract' => RuntimeBuildMetadata::RUNTIME_CONTRACT,
        'tenant_schema' => RuntimeBuildMetadata::TENANT_SCHEMA,
        'roles' => array_map(
            static fn (NexusProcessRole $role): string => $role->value,
            NexusProcessRole::cases(),
        ),
    ];
    $directory = dirname($output);

    if ((! is_dir($directory) && ! mkdir($directory, 0755, true)) || ! is_writable($directory)) {
        throw new RuntimeException('Build metadata output directory is unavailable.');
    }

    if (file_put_contents(
        $output,
        json_encode(
            $metadata,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        )."\n",
        LOCK_EX,
    ) === false) {
        throw new RuntimeException('Build metadata could not be written.');
    }
} catch (Throwable) {
    fwrite(STDERR, 'Build metadata generation failed.'.PHP_EOL);
    exit(1);
}

/** @param array<string, mixed> $options */
function requiredOption(array $options, string $name): string
{
    $value = $options[$name] ?? null;

    if (! is_string($value) || $value === '') {
        throw new RuntimeException('A required build metadata option is missing.');
    }

    return $value;
}
