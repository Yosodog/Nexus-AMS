<?php

namespace App\Services\StaffWorkQueue;

use App\Enums\OperationsNextActor;

final readonly class StaffWorkQueueAction
{
    public function __construct(
        public string $key,
        public string $label,
        public OperationsNextActor $actor,
        public ?string $url = null,
        public bool $requiresPreview = false,
        public bool $batchable = false,
    ) {}

    /** @return array{key: string, label: string, actor: string, url: string|null, requires_preview: bool, batchable: bool} */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'actor' => $this->actor->value,
            'url' => $this->url,
            'requires_preview' => $this->requiresPreview,
            'batchable' => $this->batchable,
        ];
    }
}
