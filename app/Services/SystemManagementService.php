<?php

namespace App\Services;

use App\Enums\SystemComponent;
use App\Exceptions\UpdaterException;
use App\Models\User;
use App\Services\Updater\UpdaterClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class SystemManagementService
{
    public function __construct(
        private readonly UpdaterClient $updater,
        private readonly AuditLogger $auditLogger,
    ) {}

    /** @return array<string, mixed> */
    public function snapshot(bool $refreshUpdates = true): array
    {
        $status = $this->updater->status();
        $updates = [
            'update_available' => false,
            'installed_release' => $status['installed_release_id'] ?? null,
            'latest_release' => $status['installed_release_id'] ?? null,
            'releases' => [],
        ];

        if ($refreshUpdates && ($status['available'] ?? false) === true && ($status['installed'] ?? false) === true) {
            try {
                $updates = $this->updater->checkUpdates();
            } catch (UpdaterException $exception) {
                $updates['error_code'] = $exception->errorCode;
            }
        }

        return [
            'updater' => $status,
            'updates' => $updates,
            'components' => is_array($status['components'] ?? null) ? $status['components'] : [],
            'operations' => is_array($status['operations'] ?? null) ? $status['operations'] : [],
            'cleanup' => is_array($status['cleanup'] ?? null) ? $status['cleanup'] : [
                'reclaimable_bytes' => 0,
                'entries' => [],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function operationStatus(string $operationId): array
    {
        return $this->updater->operation($operationId);
    }

    /** @return array<string, mixed> */
    public function startUpdate(User $actor): array
    {
        return $this->startMutation($actor, 'update', fn (string $id): array => $this->updater->update($id));
    }

    /** @return array<string, mixed> */
    public function rollback(User $actor): array
    {
        return $this->startMutation($actor, 'rollback', fn (string $id): array => $this->updater->rollback($id));
    }

    /** @return array<string, mixed> */
    public function cleanup(User $actor): array
    {
        return $this->startMutation($actor, 'cleanup', fn (string $id): array => $this->updater->cleanup($id));
    }

    /**
     * @param  array<string, string>  $configuration
     * @return array<string, mixed>
     */
    public function componentOperation(
        User $actor,
        SystemComponent $component,
        string $action,
        array $configuration = [],
    ): array {
        $operation = match ($action) {
            'install' => 'InstallComponent',
            'enable' => 'EnableComponent',
            'disable' => 'DisableComponent',
            'restart' => 'RestartComponent',
            default => throw new UpdaterException('Unsupported component action.', 'invalid_operation'),
        };

        return $this->startMutation(
            $actor,
            'component_'.$action,
            fn (string $id): array => $this->updater->componentOperation($operation, $id, $component, $configuration),
            $component,
        );
    }

    /**
     * @param  callable(string): array<string, mixed>  $submit
     * @return array<string, mixed>
     */
    private function startMutation(
        User $actor,
        string $action,
        callable $submit,
        ?SystemComponent $component = null,
    ): array {
        $operationId = strtolower((string) Str::uuid());

        try {
            $response = $submit($operationId);
            $this->audit('requested', $action, $actor, $operationId, $component);

            return [
                ...$response,
                'id' => $response['operation_id'] ?? $operationId,
            ];
        } catch (UpdaterException $exception) {
            $this->audit('rejected', $action, $actor, $operationId, $component, $exception->errorCode);

            throw $exception;
        }
    }

    private function audit(
        string $outcome,
        string $action,
        User $actor,
        string $operationId,
        ?SystemComponent $component = null,
        ?string $errorCode = null,
    ): void {
        try {
            $this->auditLogger->record(
                category: 'system_management',
                action: $action,
                outcome: $outcome,
                severity: $outcome === 'requested' ? 'warning' : 'error',
                context: array_filter([
                    'operation_id' => $operationId,
                    'component_id' => $component?->value,
                    'error_code' => $errorCode,
                ]),
                actorOverride: [
                    'type' => 'user',
                    'id' => (int) $actor->getAuthIdentifier(),
                    'name' => $actor->name,
                ],
            );
        } catch (Throwable $exception) {
            Log::error('Unable to record Nexus system-management audit event.', [
                'action' => $action,
                'operation_id' => $operationId,
                'exception' => $exception::class,
            ]);
        }
    }
}
