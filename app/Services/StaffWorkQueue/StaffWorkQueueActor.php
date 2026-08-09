<?php

namespace App\Services\StaffWorkQueue;

final readonly class StaffWorkQueueActor
{
    public function __construct(
        public string $kind,
        public string $key,
        public string $label,
        public ?string $url = null,
    ) {}

    /** @return array{kind: string, key: string, label: string, url: string|null} */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'key' => $this->key,
            'label' => $this->label,
            'url' => $this->url,
        ];
    }
}
