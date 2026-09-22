<?php

namespace App\Services\Updater;

use App\Enums\SystemComponent;
use App\Exceptions\UpdaterException;
use App\Exceptions\UpdaterSubmissionUnknownException;
use App\Exceptions\UpdaterUnavailableException;
use Illuminate\Support\Str;
use Throwable;

class UpdaterClient
{
    private const OPERATIONS = [
        'GetStatus',
        'CheckUpdates',
        'Doctor',
        'Update',
        'Rollback',
        'Cleanup',
        'GetOperation',
        'ListComponents',
        'InstallComponent',
        'EnableComponent',
        'DisableComponent',
        'RestartComponent',
    ];

    private const MUTATING_OPERATIONS = [
        'Update',
        'Rollback',
        'Cleanup',
        'InstallComponent',
        'EnableComponent',
        'DisableComponent',
        'RestartComponent',
    ];

    /** @return array<string, mixed> */
    public function status(): array
    {
        try {
            return $this->request('GetStatus');
        } catch (UpdaterException $exception) {
            return [
                'available' => false,
                'error_code' => $exception->errorCode,
            ];
        }
    }

    /** @return array<string, mixed> */
    public function checkUpdates(): array
    {
        return $this->request('CheckUpdates');
    }

    /** @return array<string, mixed> */
    public function doctor(): array
    {
        return $this->request('Doctor');
    }

    /** @return array<string, mixed> */
    public function components(): array
    {
        return $this->request('ListComponents');
    }

    /** @return array<string, mixed> */
    public function update(string $operationId): array
    {
        return $this->mutation('Update', $operationId);
    }

    /** @return array<string, mixed> */
    public function rollback(string $operationId): array
    {
        return $this->mutation('Rollback', $operationId);
    }

    /** @return array<string, mixed> */
    public function cleanup(string $operationId): array
    {
        return $this->mutation('Cleanup', $operationId);
    }

    /**
     * @param  array<string, string>  $configuration
     * @return array<string, mixed>
     */
    public function componentOperation(
        string $operation,
        string $operationId,
        SystemComponent $component,
        array $configuration = [],
    ): array {
        if (! in_array($operation, ['InstallComponent', 'EnableComponent', 'DisableComponent', 'RestartComponent'], true)) {
            throw new UpdaterException('Unsupported component operation.', 'invalid_operation');
        }

        if ($component === SystemComponent::Core && $operation !== 'RestartComponent') {
            throw new UpdaterException('Nexus Core is installed and updated as part of Nexus.', 'invalid_component');
        }

        $payload = [
            'operation_id' => $this->uuid($operationId),
            'component_id' => $component->value,
        ];

        if ($operation === 'InstallComponent') {
            $payload['configuration'] = $this->componentConfiguration($component, $configuration);
        } elseif ($configuration !== []) {
            throw new UpdaterException('Configuration is only accepted during component installation.', 'invalid_request');
        }

        return $this->request($operation, $payload);
    }

    /** @return array<string, mixed> */
    public function operation(string $operationId): array
    {
        return $this->request('GetOperation', [
            'operation_id' => $this->uuid($operationId),
        ]);
    }

    /** @return array<string, mixed> */
    private function mutation(string $operation, string $operationId): array
    {
        return $this->request($operation, [
            'operation_id' => $this->uuid($operationId),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function request(string $operation, array $payload = []): array
    {
        if (! in_array($operation, self::OPERATIONS, true)) {
            throw new UpdaterException('Unsupported updater operation.', 'invalid_operation');
        }

        $requestId = strtolower((string) Str::uuid());
        $payload['source'] = 'gui';
        $body = json_encode([
            'protocol_version' => (int) config('nexus.updater.protocol_version', 1),
            'request_id' => $requestId,
            'operation' => $operation,
            'payload' => $payload,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $maxRequestBytes = max(1024, (int) config('nexus.updater.max_message_bytes', 65536));
        $maxResponseBytes = max($maxRequestBytes, (int) config('nexus.updater.max_response_bytes', 1048576));

        if (strlen($body) > $maxRequestBytes) {
            throw new UpdaterException('The updater request is too large.', 'request_too_large');
        }

        $socket = $this->connect();

        try {
            try {
                $this->writeAll($socket, pack('N', strlen($body)).$body);
                $lengthBytes = $this->readExactly($socket, 4);
            } catch (Throwable $exception) {
                if ($this->isMutating($operation)) {
                    throw new UpdaterSubmissionUnknownException($payload['operation_id'], $exception);
                }

                throw new UpdaterUnavailableException($exception);
            }

            $length = unpack('Nlength', $lengthBytes)['length'] ?? 0;

            if (! is_int($length) || $length < 2 || $length > $maxResponseBytes) {
                throw $this->invalidResponse($operation, $payload, 'The updater returned an invalid response length.');
            }

            try {
                $response = json_decode($this->readExactly($socket, $length), true, 16, JSON_THROW_ON_ERROR);
            } catch (Throwable $exception) {
                if ($this->isMutating($operation)) {
                    throw new UpdaterSubmissionUnknownException($payload['operation_id'], $exception);
                }

                throw new UpdaterException('The updater response could not be read.', 'invalid_response', $exception);
            }

            if (! is_array($response)
                || ($response['protocol_version'] ?? null) !== (int) config('nexus.updater.protocol_version', 1)
                || ($response['request_id'] ?? null) !== $requestId
            ) {
                throw $this->invalidResponse($operation, $payload, 'The updater returned an invalid response.');
            }

            if (($response['ok'] ?? false) !== true) {
                throw new UpdaterException(
                    $this->safeMessage($response['error_message'] ?? null) ?? 'The updater rejected the request.',
                    $this->safeCode($response['error_code'] ?? null) ?? 'updater_error',
                );
            }

            $data = $response['data'] ?? [];

            if (! is_array($data)) {
                throw $this->invalidResponse($operation, $payload, 'The updater returned an invalid data payload.');
            }

            return $data;
        } finally {
            fclose($socket);
        }
    }

    /** @return resource */
    private function connect()
    {
        $path = config('nexus.updater.socket', '/run/nexus-updater/control.sock');

        if (! is_string($path) || ! str_starts_with($path, '/') || strlen($path) > 100) {
            throw new UpdaterException('The updater socket is not safely configured.', 'invalid_configuration');
        }

        $socket = @stream_socket_client(
            'unix://'.$path,
            $errorCode,
            $errorMessage,
            max(1, (int) config('nexus.updater.connect_timeout_seconds', 3)),
            STREAM_CLIENT_CONNECT,
        );

        if (! is_resource($socket)) {
            throw new UpdaterUnavailableException;
        }

        stream_set_timeout($socket, max(1, (int) config('nexus.updater.request_timeout_seconds', 10)));

        return $socket;
    }

    /** @param resource $socket */
    private function writeAll($socket, string $data): void
    {
        $offset = 0;

        while ($offset < strlen($data)) {
            $written = fwrite($socket, substr($data, $offset));

            if ($written === false || $written === 0) {
                throw new UpdaterUnavailableException;
            }

            $offset += $written;
        }
    }

    /** @param resource $socket */
    private function readExactly($socket, int $length): string
    {
        $data = '';

        while (strlen($data) < $length) {
            $chunk = fread($socket, $length - strlen($data));

            if ($chunk === false || $chunk === '') {
                throw new UpdaterUnavailableException;
            }

            $data .= $chunk;
        }

        return $data;
    }

    private function uuid(string $value): string
    {
        if (! Str::isUuid($value)) {
            throw new UpdaterException('The updater operation identifier is invalid.', 'invalid_request');
        }

        return strtolower($value);
    }

    private function safeCode(mixed $value): ?string
    {
        return is_string($value) && preg_match('/\A[a-zA-Z0-9._-]{1,120}\z/D', $value) === 1
            ? $value
            : null;
    }

    private function safeMessage(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' && strlen($value) <= 256 && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1
            ? $value
            : null;
    }

    private function isMutating(string $operation): bool
    {
        return in_array($operation, self::MUTATING_OPERATIONS, true);
    }

    /** @param array<string, mixed> $payload */
    private function invalidResponse(string $operation, array $payload, string $message): UpdaterException
    {
        return $this->isMutating($operation)
            ? new UpdaterSubmissionUnknownException((string) ($payload['operation_id'] ?? ''))
            : new UpdaterException($message, 'invalid_response');
    }

    /**
     * @param  array<string, string>  $configuration
     * @return array<string, string>
     */
    private function componentConfiguration(SystemComponent $component, array $configuration): array
    {
        if ($component === SystemComponent::Subs) {
            if ($configuration !== []) {
                throw new UpdaterException('Local Subs reuses Nexus Core configuration.', 'invalid_request');
            }

            return [];
        }

        if ($component !== SystemComponent::Discord || array_diff(array_keys($configuration), ['bot_token', 'client_id', 'guild_id']) !== []) {
            throw new UpdaterException('Unsupported component configuration.', 'invalid_request');
        }

        foreach (['bot_token', 'client_id', 'guild_id'] as $field) {
            $value = $configuration[$field] ?? '';

            if (! is_string($value) || $value === '' || strlen($value) > 512 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                throw new UpdaterException('The component configuration is invalid.', 'invalid_request');
            }
        }

        foreach (['client_id', 'guild_id'] as $field) {
            if (preg_match('/\A[0-9]{17,20}\z/D', $configuration[$field]) !== 1) {
                throw new UpdaterException('The component identifier is invalid.', 'invalid_request');
            }
        }

        return $configuration;
    }
}
