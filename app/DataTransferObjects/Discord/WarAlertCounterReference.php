<?php

namespace App\DataTransferObjects\Discord;

final readonly class WarAlertCounterReference
{
    public function __construct(
        public string $kind,
        public int $id,
        public string $url,
    ) {}

    /** @return array{kind: string, id: int, url: string} */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'id' => $this->id,
            'url' => $this->url,
        ];
    }
}
