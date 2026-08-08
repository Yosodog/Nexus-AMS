<?php

namespace App\Services\Alerts;

use App\DataTransferObjects\Alerts\AlertEventDefinition;
use App\Enums\AlertAudience;
use App\Enums\AlertDestinationKind;
use App\Enums\AlertSensitivity;
use App\Enums\AlertSeverity;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use LogicException;

class AlertEventCatalog
{
    /** @var array<string, AlertEventDefinition>|null */
    private ?array $definitions = null;

    /** @return array<string, AlertEventDefinition> */
    public function all(): array
    {
        if ($this->definitions !== null) {
            return $this->definitions;
        }

        $definitions = collect($this->buildDefinitions());
        if ($definitions->pluck('key')->unique()->count() !== $definitions->count()) {
            throw new LogicException('Alert event keys must be unique.');
        }

        if ($definitions->contains(fn (AlertEventDefinition $definition): bool => str_contains($definition->key, 'war_assignment')
            || str_contains($definition->key, 'spy_assignment'))) {
            throw new LogicException('Proactive war and spy assignment events are not supported.');
        }

        return $this->definitions = $definitions
            ->keyBy(fn (AlertEventDefinition $definition): string => $definition->key)
            ->all();
    }

    public function get(string $eventKey): AlertEventDefinition
    {
        return $this->all()[$eventKey]
            ?? throw new InvalidArgumentException("Unknown alert event [{$eventKey}].");
    }

    public function has(string $eventKey): bool
    {
        return isset($this->all()[$eventKey]);
    }

    /** @return array<string, AlertEventDefinition> */
    public function memberSubscriptionEvents(): array
    {
        return Arr::only($this->all(), [
            'nation.alliance.changed',
            'nation.vacation.entered',
            'nation.vacation.exited',
            'nation.beige.exited',
            'nation.city_count.changed',
            'nation.active_wars.changed',
            'alliance.membership.changed',
            'alliance.treaty.changed',
            'market.price.crossed',
        ]);
    }

    /** @return array{contract_version:int,capabilities:array{queue_lanes:bool},templates:list<array{template_key:string,version:int,event_keys:list<string>,active:bool}>} */
    public function rendererManifest(): array
    {
        $templates = collect($this->all())
            ->groupBy(fn (AlertEventDefinition $definition): string => $definition->templateKey)
            ->map(fn ($definitions, string $templateKey): array => [
                'template_key' => $templateKey,
                'version' => 1,
                'event_keys' => $definitions
                    ->map(fn (AlertEventDefinition $definition): string => $definition->key)
                    ->sort()
                    ->values()
                    ->all(),
                'active' => true,
            ])
            ->values();

        $templates->push([
            'template_key' => 'digest.v1',
            'version' => 1,
            'event_keys' => collect(array_keys($this->all()))->sort()->values()->all(),
            'active' => true,
        ]);

        return [
            'contract_version' => 1,
            'capabilities' => ['queue_lanes' => true],
            'templates' => $templates->all(),
        ];
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function safePayload(string $eventKey, array $payload): array
    {
        $safe = [];
        foreach (Arr::only($payload, $this->get($eventKey)->payloadKeys) as $key => $value) {
            if (! $this->isSafePayloadValue($value)) {
                continue;
            }

            $safe[$key] = match (true) {
                is_string($value) => (string) str($value)->limit(500, ''),
                is_array($value) => collect($value)
                    ->map(fn (mixed $item): mixed => is_string($item)
                        ? (string) str($item)->limit(500, '')
                        : $item)
                    ->all(),
                default => $value,
            };
        }

        return $safe;
    }

    private function isSafePayloadValue(mixed $value): bool
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return true;
        }

        if (is_string($value)) {
            return ! preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value);
        }

        return is_array($value)
            && array_is_list($value)
            && count($value) <= 20
            && collect($value)->every(fn (mixed $item): bool => is_scalar($item)
                && (! is_string($item) || ! preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $item)));
    }

    /** @return list<AlertEventDefinition> */
    private function buildDefinitions(): array
    {
        $memberDestinations = [AlertDestinationKind::Web, AlertDestinationKind::DiscordDm];
        $staffDestinations = [AlertDestinationKind::Web, AlertDestinationKind::DiscordChannel];
        $adminDestinations = [AlertDestinationKind::Web, AlertDestinationKind::DiscordChannel];

        return [
            $this->definition('nation.alliance.changed', AlertAudience::Member, AlertSensitivity::Public, AlertSeverity::Normal, $memberDestinations, 'member_alert_v1', 'nation', ['label', 'old_alliance_id', 'alliance_id'], 'mark', 120),
            $this->definition('nation.vacation.entered', AlertAudience::Member, AlertSensitivity::Public, AlertSeverity::Normal, $memberDestinations, 'member_alert_v1', 'nation', ['label', 'vacation_mode'], 'mark', 120),
            $this->definition('nation.vacation.exited', AlertAudience::Member, AlertSensitivity::Public, AlertSeverity::Normal, $memberDestinations, 'member_alert_v1', 'nation', ['label', 'vacation_mode'], 'mark', 120),
            $this->definition('nation.beige.exited', AlertAudience::Member, AlertSensitivity::Public, AlertSeverity::Normal, $memberDestinations, 'member_alert_v1', 'nation', ['label', 'beige'], 'mark', 120),
            $this->definition('nation.city_count.changed', AlertAudience::Member, AlertSensitivity::Public, AlertSeverity::Normal, $memberDestinations, 'member_alert_v1', 'nation', ['label', 'old_cities', 'cities'], 'mark', 120),
            $this->definition('nation.active_wars.changed', AlertAudience::Member, AlertSensitivity::Public, AlertSeverity::Normal, $memberDestinations, 'member_alert_v1', 'nation', ['label', 'offensive_wars', 'defensive_wars'], 'mark', 120),
            $this->definition('alliance.membership.changed', AlertAudience::Member, AlertSensitivity::Public, AlertSeverity::Normal, $memberDestinations, 'member_alert_v1', 'alliance', ['label', 'added', 'removed'], 'mark', 120),
            $this->definition('alliance.treaty.changed', AlertAudience::Member, AlertSensitivity::Public, AlertSeverity::Normal, $memberDestinations, 'member_alert_v1', 'alliance', ['label', 'added', 'removed'], 'mark', 120),
            $this->definition('market.price.crossed', AlertAudience::Member, AlertSensitivity::Public, AlertSeverity::Normal, $memberDestinations, 'member_alert_v1', 'market', ['resource', 'direction', 'threshold', 'price', 'observed_at'], 'mark', 120),

            $this->definition('application.status.changed', AlertAudience::Member, AlertSensitivity::Member, AlertSeverity::Normal, $memberDestinations, 'workflow_status_v1', 'ownership', ['label', 'status'], 'supersede'),
            $this->definition('finance.grant.status.changed', AlertAudience::Member, AlertSensitivity::Restricted, AlertSeverity::Normal, $memberDestinations, 'workflow_status_v1', 'ownership', ['label', 'status'], 'supersede'),
            $this->definition('finance.city_grant.status.changed', AlertAudience::Member, AlertSensitivity::Restricted, AlertSeverity::Normal, $memberDestinations, 'workflow_status_v1', 'ownership', ['label', 'status'], 'supersede'),
            $this->definition('finance.loan.status.changed', AlertAudience::Member, AlertSensitivity::Restricted, AlertSeverity::Normal, $memberDestinations, 'workflow_status_v1', 'ownership', ['label', 'status'], 'supersede'),
            $this->definition('finance.war_aid.status.changed', AlertAudience::Member, AlertSensitivity::Restricted, AlertSeverity::Normal, $memberDestinations, 'workflow_status_v1', 'ownership', ['label', 'status'], 'supersede'),
            $this->definition('finance.rebuilding.status.changed', AlertAudience::Member, AlertSensitivity::Restricted, AlertSeverity::Normal, $memberDestinations, 'workflow_status_v1', 'ownership', ['label', 'status'], 'supersede'),
            $this->definition('finance.deposit.status.changed', AlertAudience::Member, AlertSensitivity::Restricted, AlertSeverity::Normal, $memberDestinations, 'workflow_status_v1', 'ownership', ['label', 'status'], 'supersede'),
            $this->definition('finance.withdrawal.status.changed', AlertAudience::Member, AlertSensitivity::Restricted, AlertSeverity::Normal, $memberDestinations, 'workflow_status_v1', 'ownership', ['label', 'status'], 'supersede'),
            $this->definition('audit.summary.ready', AlertAudience::Member, AlertSensitivity::Member, AlertSeverity::Normal, $memberDestinations, 'workflow_status_v1', 'ownership', ['finding_count', 'overdue_count'], 'deliver'),
            $this->definition('blockade_relief.request.changed', AlertAudience::Member, AlertSensitivity::Member, AlertSeverity::High, $memberDestinations, 'workflow_status_v1', 'ownership', ['label', 'status', 'event'], 'supersede'),

            $this->definition('milcom.incident.detected', AlertAudience::Staff, AlertSensitivity::Internal, AlertSeverity::Critical, $staffDestinations, 'milcom_alert_v1', 'milcom', ['war_id', 'incident_id', 'attacked_nation_id', 'aggressor_nation_id', 'label'], 'quarantine', 15, 'manage-war-room', true),
            $this->definition('milcom.raid_policy.violation', AlertAudience::Staff, AlertSensitivity::Internal, AlertSeverity::High, $staffDestinations, 'milcom_alert_v1', 'milcom', ['war_id', 'friendly_nation_name', 'target_nation_name', 'label'], 'mark', 30, 'manage-war-room'),
            $this->definition('milcom.discord_dispatch.failed', AlertAudience::Staff, AlertSensitivity::Internal, AlertSeverity::High, $staffDestinations, 'milcom_alert_v1', 'milcom', ['dispatch_id', 'queue_id', 'operation_id', 'objective_id', 'label'], 'deliver', null, 'manage-war-room', true),
            $this->definition('beige.turn.window', AlertAudience::Staff, AlertSensitivity::Internal, AlertSeverity::Normal, $staffDestinations, 'operational_alert_v1', 'alliance', ['label', 'nations', 'turn'], 'mark', 15, 'manage-war-room'),
            $this->definition('beige.early_exit', AlertAudience::Staff, AlertSensitivity::Internal, AlertSeverity::High, $staffDestinations, 'operational_alert_v1', 'alliance', ['label', 'nation_id', 'alliance_id'], 'suppress', 5, 'manage-war-room'),
            $this->definition('member.inactivity.entered', AlertAudience::Staff, AlertSensitivity::Internal, AlertSeverity::Normal, $staffDestinations, 'operational_alert_v1', 'member_group', ['label', 'nation_id', 'alliance_id', 'days_inactive'], 'mark', 120),
            $this->definition('member.inactivity.reminder', AlertAudience::Staff, AlertSensitivity::Internal, AlertSeverity::Normal, $staffDestinations, 'operational_alert_v1', 'member_group', ['label', 'nation_id', 'alliance_id', 'days_inactive'], 'mark', 120),
            $this->definition('member.departed', AlertAudience::Staff, AlertSensitivity::Internal, AlertSeverity::Normal, $staffDestinations, 'operational_alert_v1', 'alliance', ['label', 'nation_id', 'old_alliance_id', 'alliance_id'], 'deliver'),
            $this->definition('discord.destination.unhealthy', AlertAudience::Administrator, AlertSensitivity::Internal, AlertSeverity::High, $adminDestinations, 'operational_alert_v1', 'destination', ['destination_id', 'failure_code', 'label'], 'deliver', null, 'view-diagnostic-info'),
            $this->definition('ingestion.record.quarantined', AlertAudience::Administrator, AlertSensitivity::Internal, AlertSeverity::High, [AlertDestinationKind::Web], 'operational_alert_v1', 'source', ['source', 'reason', 'label'], 'deliver', null, 'view-diagnostic-info'),
        ];
    }

    /**
     * @param  list<AlertDestinationKind>  $destinations
     * @param  list<string>  $payloadKeys
     */
    private function definition(
        string $key,
        AlertAudience $audience,
        AlertSensitivity $sensitivity,
        AlertSeverity $severity,
        array $destinations,
        string $template,
        string $filterType,
        array $payloadKeys,
        string $stalePolicy,
        ?int $staleAfterMinutes = null,
        ?string $permission = null,
        bool $mayBypassQuietHours = false,
    ): AlertEventDefinition {
        return new AlertEventDefinition(
            key: $key,
            schemaVersion: 1,
            audience: $audience,
            sensitivity: $sensitivity,
            severity: $severity,
            allowedDestinations: $destinations,
            templateKey: $template,
            filterType: $filterType,
            payloadKeys: $payloadKeys,
            stalePolicy: $stalePolicy,
            staleAfterMinutes: $staleAfterMinutes,
            requiredPermission: $permission,
            mayBypassQuietHours: $mayBypassQuietHours,
        );
    }
}
