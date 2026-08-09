#!/usr/local/bin/php
<?php

declare(strict_types=1);

use App\Enums\NexusProcessRole;

$applicationRoot = dirname(__DIR__, 2);
require $applicationRoot.'/vendor/autoload.php';

$runtimeDirectory = '/tmp/nexus-runtime';
$roleValue = readRuntimeValue($runtimeDirectory.'/role');
$childPid = filter_var(
    readRuntimeValue($runtimeDirectory.'/child.pid'),
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 2]],
);
$role = is_string($roleValue) ? NexusProcessRole::tryFrom($roleValue) : null;

if ($role === null || ! is_int($childPid) || ! @posix_kill($childPid, 0)) {
    exit(1);
}

if ($role !== NexusProcessRole::Web) {
    exit(0);
}

$socket = @stream_socket_client(
    'tcp://127.0.0.1:8080',
    $errorCode,
    $errorMessage,
    2.0,
    STREAM_CLIENT_CONNECT,
);

if (! is_resource($socket)) {
    exit(1);
}

stream_set_timeout($socket, 2);
fwrite($socket, "GET /up HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
$statusLine = fgets($socket, 256);
fclose($socket);

exit(is_string($statusLine) && preg_match('/\AHTTP\/1\.[01] 200(?: |\r?\n)/', $statusLine) === 1 ? 0 : 1);

function readRuntimeValue(string $path): ?string
{
    if (is_link($path) || ! is_file($path) || ! is_readable($path)) {
        return null;
    }

    $value = @file_get_contents($path, false, null, 0, 128);

    return is_string($value) ? trim($value) : null;
}
