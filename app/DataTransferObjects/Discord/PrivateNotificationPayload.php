<?php

namespace App\DataTransferObjects\Discord;

use Carbon\CarbonInterface;

final readonly class PrivateNotificationPayload
{
    /**
     * @param  array{type:string,id:int|string,label?:string}  $subject
     * @param  array<string, bool|int|float|string|null>  $summary
     */
    public function __construct(
        public string $eventType,
        public string $recipientDiscordId,
        public string $notificationId,
        public array $subject,
        public CarbonInterface $occurredAt,
        public string $deepLinkPath,
        public array $summary,
    ) {}

    /**
     * @return array{
     *     contract_version:int,
     *     event_type:string,
     *     recipient_discord_id:string,
     *     notification_id:string,
     *     subject:array{type:string,id:int|string,label?:string},
     *     occurred_at:string,
     *     deep_link_path:string,
     *     summary:array<string, bool|int|float|string|null>
     * }
     */
    public function toArray(): array
    {
        return [
            'contract_version' => 1,
            'event_type' => $this->eventType,
            'recipient_discord_id' => $this->recipientDiscordId,
            'notification_id' => $this->notificationId,
            'subject' => $this->subject,
            'occurred_at' => $this->occurredAt->toIso8601String(),
            'deep_link_path' => $this->deepLinkPath,
            'summary' => $this->summary,
        ];
    }
}
