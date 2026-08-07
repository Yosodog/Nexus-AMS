<?php

namespace App\DataTransferObjects\Subscriptions;

final readonly class ValidatedSubscriptionEvent
{
    /**
     * @param  list<array<string, mixed>>  $records
     */
    public function __construct(
        public string $model,
        public string $event,
        public array $records,
    ) {}

    public function key(): string
    {
        return $this->model.':'.$this->event;
    }
}
