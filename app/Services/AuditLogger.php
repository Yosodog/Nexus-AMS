<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AuditLogger
{
    private const MESSAGE_MAX = 255;

    private const USER_AGENT_MAX = 512;

    private const CONTEXT_STRING_MAX = 1000;

    public function __construct(private readonly AuditRequestContext $requestContext) {}

    /**
     * @param  array<string, mixed>  $context
     * @param  array{type?: string, id?: int|null, name?: string|null}|null  $actorOverride
     */
    public function record(
        string $category,
        string $action,
        string $outcome = 'success',
        string $severity = 'info',
        ?object $subject = null,
        array $context = [],
        ?string $message = null,
        ?array $actorOverride = null,
    ): void {
        $actor = $this->resolveActor($actorOverride);
        $subjectData = $this->resolveSubject($subject);

        $context = $this->mergeRequestContext($context);
        $context = $this->sanitizeContext($context);

        AuditLog::query()->create([
            'occurred_at' => now(),
            'request_id' => $this->requestContext->requestId(),
            'ip' => $this->sanitizeString($this->requestContext->ip(), 45),
            'user_agent' => $this->sanitizeString($this->requestContext->userAgent(), self::USER_AGENT_MAX),
            'actor_type' => $this->sanitizeString($actor['type'], 60) ?? 'system',
            'actor_id' => $actor['id'],
            'actor_name' => $this->sanitizeString($actor['name'] ?? null, 255),
            'category' => $this->sanitizeString($category, 100) ?? 'system',
            'action' => $this->sanitizeString($action, 120) ?? 'unknown',
            'outcome' => $this->sanitizeString($outcome, 60) ?? 'unknown',
            'severity' => $this->sanitizeString($severity, 60) ?? 'info',
            'message' => $this->sanitizeString($message, self::MESSAGE_MAX),
            'subject_type' => $this->sanitizeString($subjectData['type'], 120),
            'subject_id' => $subjectData['id'],
            'context' => empty($context) ? null : $context,
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array{type?: string, id?: int|null, name?: string|null}|null  $actorOverride
     */
    public function recordAfterCommit(
        string $category,
        string $action,
        string $outcome = 'success',
        string $severity = 'info',
        ?object $subject = null,
        array $context = [],
        ?string $message = null,
        ?array $actorOverride = null,
    ): void {
        DB::afterCommit(function () use ($category, $action, $outcome, $severity, $subject, $context, $message, $actorOverride) {
            $this->record($category, $action, $outcome, $severity, $subject, $context, $message, $actorOverride);
        });
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array{type?: string, id?: int|null, name?: string|null}|null  $actorOverride
     */
    public function success(
        string $category,
        string $action,
        ?object $subject = null,
        array $context = [],
        ?string $message = null,
        ?array $actorOverride = null,
    ): void {
        $this->record($category, $action, 'success', 'info', $subject, $context, $message, $actorOverride);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array{type?: string, id?: int|null, name?: string|null}|null  $actorOverride
     */
    public function denied(
        string $category,
        string $action,
        ?object $subject = null,
        array $context = [],
        ?string $message = null,
        ?array $actorOverride = null,
    ): void {
        $this->record($category, $action, 'denied', 'warning', $subject, $context, $message, $actorOverride);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array{type?: string, id?: int|null, name?: string|null}|null  $actorOverride
     */
    public function failure(
        string $category,
        string $action,
        ?object $subject = null,
        array $context = [],
        ?string $message = null,
        ?array $actorOverride = null,
    ): void {
        $this->record($category, $action, 'failure', 'critical', $subject, $context, $message, $actorOverride);
    }

    /**
     * @param  array{type?: string, id?: int|null, name?: string|null}|null  $actorOverride
     * @return array{type: string, id: int|null, name: string|null}
     */
    private function resolveActor(?array $actorOverride): array
    {
        if ($actorOverride) {
            $type = $actorOverride['type'] ?? 'system';
            $id = $actorOverride['id'] ?? null;
            $name = $actorOverride['name'] ?? null;

            return [
                'type' => is_string($type) && $type !== '' ? $type : 'system',
                'id' => is_numeric($id) ? (int) $id : null,
                'name' => is_string($name) ? $name : null,
            ];
        }

        $actor = $this->requestContext->actor();

        if ($actor) {
            return [
                'type' => $actor['type'] ?? 'user',
                'id' => $actor['id'] ?? null,
                'name' => $actor['name'] ?? null,
            ];
        }

        return [
            'type' => 'system',
            'id' => null,
            'name' => null,
        ];
    }

    /**
     * @return array{type: string|null, id: string|null}
     */
    private function resolveSubject(?object $subject): array
    {
        if (! $subject) {
            return ['type' => null, 'id' => null];
        }

        if ($subject instanceof Model) {
            return [
                'type' => $subject->getMorphClass(),
                'id' => $subject->getKey() !== null ? (string) $subject->getKey() : null,
            ];
        }

        return [
            'type' => $subject::class,
            'id' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function mergeRequestContext(array $context): array
    {
        $requestMeta = array_filter([
            'route' => $this->requestContext->routeName(),
            'method' => $this->requestContext->method(),
        ], fn ($value) => $value !== null);

        if ($requestMeta !== [] && ! array_key_exists('request', $context)) {
            $context = ['request' => $requestMeta, ...$context];
        }

        return $context;
    }

    private function sanitizeString(?string $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }

        $cleaned = preg_replace('/[\\x00-\\x1F\\x7F]/u', ' ', $value);
        $cleaned = trim((string) $cleaned);

        if ($cleaned === '') {
            return null;
        }

        return Str::limit($cleaned, $maxLength, '');
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function sanitizeContext(array $context): array
    {
        $sanitized = [];

        foreach ($context as $key => $value) {
            $sanitized[$key] = $this->sanitizeContextValue($value);
        }

        return $sanitized;
    }

    private function sanitizeContextValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->sanitizeString($value, self::CONTEXT_STRING_MAX);
        }

        if (is_array($value)) {
            $cleaned = [];

            foreach ($value as $key => $nested) {
                $cleaned[$key] = $this->sanitizeContextValue($nested);
            }

            return $cleaned;
        }

        return $value;
    }
}
