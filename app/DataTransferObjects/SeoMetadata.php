<?php

namespace App\DataTransferObjects;

final readonly class SeoMetadata
{
    /**
     * @param  array<string, mixed>|null  $structuredData
     */
    public function __construct(
        public string $title,
        public string $description,
        public string $canonical,
        public string $robots,
        public string $siteName,
        public bool $indexable,
        public ?string $imageUrl = null,
        public ?string $imageAlt = null,
        public ?array $structuredData = null,
    ) {}

    public function twitterCard(): string
    {
        return $this->imageUrl === null ? 'summary' : 'summary_large_image';
    }
}
