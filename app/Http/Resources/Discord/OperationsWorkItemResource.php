<?php

namespace App\Http\Resources\Discord;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class OperationsWorkItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $item */
        $item = $this->resource;

        return [
            'work_key' => (string) ($item['work_key'] ?? $item['key']),
            'occurrence_key' => (string) $item['occurrence_key'],
            'source' => [
                'type' => (string) ($item['source_type'] ?? $item['type']),
                'label' => (string) ($item['source_label'] ?? $item['type_label']),
                'sensitivity' => (string) ($item['sensitivity'] ?? 'restricted'),
            ],
            'domain_id' => $item['domain_id'] ?? $item['id'],
            'title' => (string) ($item['title'] ?? $item['subject']),
            'summary' => $item['summary'] ?? null,
            'status' => [
                'code' => (string) data_get($item, 'domain_status.code', $item['status_label'] ?? ''),
                'label' => (string) data_get($item, 'domain_status.label', $item['status_label'] ?? ''),
                'intent' => (string) data_get($item, 'domain_status.intent', $item['status_intent'] ?? 'neutral'),
                'icon' => (string) data_get($item, 'domain_status.icon', $item['status_icon'] ?? ''),
            ],
            'routing' => [
                'responsible_team' => $item['team_key'] ?? null,
                'interested_teams' => array_values((array) ($item['interested_teams'] ?? [])),
            ],
            'actors' => [
                'requester' => $this->actor($item['requester'] ?? null),
                'owner' => $this->actor($item['domain_owner'] ?? null),
                'waiting_on' => $this->actor($item['waiting_on'] ?? null),
                'next_actor' => (string) ($item['next_actor'] ?? 'staff'),
            ],
            'attention' => [
                'priority' => (string) ($item['priority'] ?? 'p3'),
                'severity' => (string) ($item['severity'] ?? 'unknown'),
                'urgency' => (string) ($item['urgency'] ?? 'routine'),
                'reasons' => array_values((array) ($item['attention_reasons'] ?? [])),
                'requires_staff_action' => (bool) ($item['requires_staff_action'] ?? true),
                'overdue' => (bool) ($item['is_overdue'] ?? false),
                'blocked' => (bool) ($item['blocked'] ?? false),
                'blocker_summary' => $item['blocker_summary'] ?? null,
            ],
            'times' => [
                'entered_queue_at' => $item['entered_queue_at'] ?? $item['created_at'] ?? null,
                'source_changed_at' => $item['source_updated_at'] ?? null,
                'due_at' => $item['due_at'] ?? null,
                'operational_target_at' => $item['operational_target_at'] ?? null,
                'completed_at' => $item['completed_at'] ?? null,
            ],
            'freshness' => [
                'state' => (string) ($item['freshness'] ?? 'unknown'),
                'projected_at' => $item['projected_at'] ?? null,
                'source_observed_at' => $item['source_observed_at'] ?? null,
                'upstream_observed_at' => $item['upstream_observed_at'] ?? null,
                'stale_after' => $item['stale_after'] ?? null,
                'source_complete' => (bool) ($item['source_complete'] ?? false),
                'source_truncated' => (bool) ($item['source_truncated'] ?? false),
            ],
            'context' => collect((array) ($item['contexts'] ?? []))
                ->map(fn (mixed $context): ?array => $this->actor($context))
                ->filter()
                ->values()
                ->all(),
            'facts' => (array) ($item['safe_facts'] ?? []),
            'next_action' => [
                'key' => (string) data_get($item, 'next_action.key', 'domain.view'),
                'label' => (string) data_get($item, 'next_action.label', $item['next_action_label'] ?? 'View'),
                'actor' => (string) data_get($item, 'next_action.actor', $item['next_actor'] ?? 'staff'),
                'deep_link_path' => $this->safeDeepLinkPath(
                    data_get($item, 'next_action.url', $item['url'] ?? null),
                ),
            ],
        ];
    }

    /** @return array{kind: string, key: string, label: string, deep_link_path: string|null}|null */
    private function actor(mixed $actor): ?array
    {
        if (! is_array($actor)
            || ! is_string($actor['kind'] ?? null)
            || ! is_string($actor['key'] ?? null)
            || ! is_string($actor['label'] ?? null)) {
            return null;
        }

        return [
            'kind' => $actor['kind'],
            'key' => $actor['key'],
            'label' => $actor['label'],
            'deep_link_path' => $this->safeDeepLinkPath($actor['url'] ?? null),
        ];
    }

    private function safeDeepLinkPath(mixed $url): ?string
    {
        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        $url = trim($url);

        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return $url;
        }

        $target = parse_url($url);
        $application = parse_url((string) config('app.url'));

        if (! is_array($target)
            || ! is_array($application)
            || ! isset($target['scheme'], $target['host'], $application['scheme'], $application['host'])
            || mb_strtolower($target['scheme']) !== mb_strtolower($application['scheme'])
            || mb_strtolower($target['host']) !== mb_strtolower($application['host'])
            || $this->port($target) !== $this->port($application)
            || isset($target['user'])
            || isset($target['pass'])) {
            return null;
        }

        $path = (string) ($target['path'] ?? '/');
        $query = isset($target['query']) ? '?'.$target['query'] : '';

        return ($path === '' ? '/' : $path).$query;
    }

    /** @param  array<string, mixed>  $url */
    private function port(array $url): ?int
    {
        if (isset($url['port'])) {
            return (int) $url['port'];
        }

        return match (mb_strtolower((string) ($url['scheme'] ?? ''))) {
            'https' => 443,
            'http' => 80,
            default => null,
        };
    }
}
