#!/usr/local/bin/php
<?php

declare(strict_types=1);

use App\Enums\NexusProcessRole;

const EXIT_USAGE = 64;
const EXIT_CONFIG = 78;
const EXIT_SOFTWARE = 70;

$applicationRoot = dirname(__DIR__, 2);
require $applicationRoot.'/vendor/autoload.php';
umask(0007);

$arguments = array_slice($argv, 1);

if (count($arguments) > 1) {
    fail('Exactly one allowlisted process role may be selected.', EXIT_USAGE);
}

$argumentRole = $arguments[0] ?? null;
$configuredRole = getenv('NEXUS_PROCESS_ROLE');
$configuredRole = is_string($configuredRole) && $configuredRole !== '' ? $configuredRole : null;

if ($argumentRole !== null && $configuredRole !== null && ! hash_equals($configuredRole, $argumentRole)) {
    fail('The configured and requested process roles do not match.', EXIT_USAGE);
}

$role = NexusProcessRole::tryFrom($argumentRole ?? $configuredRole ?? NexusProcessRole::Web->value);

if ($role === null) {
    fail('The requested process role is not allowlisted.', EXIT_USAGE);
}

if ($role === NexusProcessRole::TenantEventConsumer && ! tenantEventConsumerIsEnabled()) {
    fail('The tenant event consumer role is not enabled for this runtime.', EXIT_CONFIG);
}

prepareRuntimeDirectories($applicationRoot);

putenv('NEXUS_PROCESS_ROLE='.$role->value);
$_ENV['NEXUS_PROCESS_ROLE'] = $role->value;
$_SERVER['NEXUS_PROCESS_ROLE'] = $role->value;

$runtimeDirectory = '/tmp/nexus-runtime';
preparePrivateDirectory($runtimeDirectory);
writeRuntimeFile($runtimeDirectory.'/role', $role->value."\n");

$command = $role->command($applicationRoot);
$process = proc_open(
    $command,
    [
        0 => STDIN,
        1 => STDOUT,
        2 => STDERR,
    ],
    $pipes,
    $applicationRoot,
);

if (! is_resource($process)) {
    fail('The selected process role could not be started.', EXIT_SOFTWARE);
}

$status = proc_get_status($process);
$childPid = $status['pid'] ?? null;

if (! is_int($childPid) || $childPid < 2) {
    proc_terminate($process, SIGKILL);
    proc_close($process);
    fail('The selected process role did not expose a valid process identifier.', EXIT_SOFTWARE);
}

writeRuntimeFile($runtimeDirectory.'/child.pid', (string) $childPid."\n");

$terminationRequested = false;
$shutdownSignal = signalNumber($role->shutdownSignal());

pcntl_async_signals(true);

$forwardTermination = static function (int $_signal) use (&$terminationRequested, $childPid, $shutdownSignal): void {
    if ($terminationRequested) {
        return;
    }

    $terminationRequested = true;
    @posix_kill($childPid, $shutdownSignal);
};

pcntl_signal(SIGTERM, $forwardTermination);
pcntl_signal(SIGINT, $forwardTermination);
pcntl_signal(SIGQUIT, $forwardTermination);

$exitCode = EXIT_SOFTWARE;

try {
    do {
        $status = proc_get_status($process);

        if (! ($status['running'] ?? false)) {
            if (($status['signaled'] ?? false) === true) {
                $exitCode = 128 + (int) ($status['termsig'] ?? 0);
            } else {
                $reportedExitCode = (int) ($status['exitcode'] ?? EXIT_SOFTWARE);
                $exitCode = $reportedExitCode >= 0 ? $reportedExitCode : EXIT_SOFTWARE;
            }

            break;
        }

        usleep(100_000);
    } while (true);
} finally {
    @unlink($runtimeDirectory.'/child.pid');
    @unlink($runtimeDirectory.'/role');
    proc_close($process);
}

exit($exitCode);

function prepareRuntimeDirectories(string $applicationRoot): void
{
    $directories = [
        $applicationRoot.'/bootstrap/cache',
        $applicationRoot.'/storage/app/private',
        $applicationRoot.'/storage/app/public',
        $applicationRoot.'/storage/framework/cache/data',
        $applicationRoot.'/storage/framework/sessions',
        $applicationRoot.'/storage/framework/views',
        $applicationRoot.'/storage/logs',
    ];

    foreach ($directories as $directory) {
        if ((! is_dir($directory) && ! @mkdir($directory, 0770, true)) || ! is_writable($directory)) {
            fail('A required mounted runtime path is unavailable.', EXIT_SOFTWARE);
        }
    }
}

function preparePrivateDirectory(string $directory): void
{
    if ((! is_dir($directory) && ! @mkdir($directory, 0700, true))
        || is_link($directory)
        || ! is_writable($directory)) {
        fail('The private runtime directory is unavailable.', EXIT_SOFTWARE);
    }

    @chmod($directory, 0700);
}

function writeRuntimeFile(string $path, string $value): void
{
    if (@file_put_contents($path, $value, LOCK_EX) === false || ! @chmod($path, 0600)) {
        fail('The private runtime state could not be written.', EXIT_SOFTWARE);
    }
}

function signalNumber(string $signal): int
{
    return match ($signal) {
        'TERM' => SIGTERM,
        'WINCH' => defined('SIGWINCH') ? SIGWINCH : SIGTERM,
        default => SIGTERM,
    };
}

function tenantEventConsumerIsEnabled(): bool
{
    return getenv('NEXUS_RUNTIME') === 'hosted-tenant'
        && environmentBoolean('NEXUS_MANAGED')
        && environmentBoolean('NEXUS_TENANT_EVENTS_ENABLED');
}

function environmentBoolean(string $name): bool
{
    $value = getenv($name);

    return is_string($value)
        && in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
}

function fail(string $message, int $status): never
{
    fwrite(STDERR, $message.PHP_EOL);
    exit($status);
}
