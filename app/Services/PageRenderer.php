<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Render sanitized HTML fragments from Editor.js block payloads.
 */
class PageRenderer
{
    private const IMAGE_STORAGE_PREFIX = '/storage/';
    private const CUSTOM_IMAGE_ROUTE_PREFIX = '/admin/customization/images/';

    /**
     * @var array<int, string>
     */
    private array $allowedImageHosts;

    public function __construct()
    {
        $this->allowedImageHosts = $this->resolveAllowedImageHosts();
    }

    /**
     * Transform Editor.js blocks into sanitized DaisyUI/Tailwind-ready markup.
     *
     * @param  array<int, mixed>  $blocks
     */
    public function render(array $blocks): string
    {
        return collect($blocks)
            ->map(fn ($block) => is_array($block) ? $this->renderBlock($block) : '')
            ->filter()
            ->implode(PHP_EOL);
    }

    /**
     * Render an individual block into HTML.
     *
     * @param  array<string, mixed>  $block
     */
    protected function renderBlock(array $block): string
    {
        return match ($block['type'] ?? null) {
            'paragraph' => $this->renderParagraph($block),
            'header' => $this->renderHeader($block),
            'list' => $this->renderList($block),
            'quote' => $this->renderQuote($block),
            'image' => $this->renderImage($block),
            'embed' => $this->renderEmbed($block),
            default => '',
        };
    }

    /**
     * @param  array<string, mixed>  $block
     */
    protected function renderParagraph(array $block): string
    {
        $text = $this->sanitizeMultiline(Arr::get($block, 'data.text'));

        if ($text === null) {
            return '';
        }

        return sprintf(
            '<p class="mb-4 leading-relaxed text-base-content">%s</p>',
            nl2br(e($text))
        );
    }

    /**
     * @param  array<string, mixed>  $block
     */
    protected function renderHeader(array $block): string
    {
        $level = (int) ($block['data']['level'] ?? 2);
        $level = $level >= 1 && $level <= 6 ? $level : 2;
        $text = $this->sanitizeInline(Arr::get($block, 'data.text'));

        if ($text === null) {
            return '';
        }

        $sizes = [
            1 => 'text-4xl',
            2 => 'text-3xl',
            3 => 'text-2xl',
            4 => 'text-xl',
            5 => 'text-lg',
            6 => 'text-base',
        ];
        $sizeClass = $sizes[$level] ?? $sizes[2];

        return sprintf(
            '<h%d class="mt-8 mb-3 font-semibold text-base-content %s">%s</h%d>',
            $level,
            $sizeClass,
            e($text),
            $level
        );
    }

    /**
     * @param  array<string, mixed>  $block
     */
    protected function renderList(array $block): string
    {
        $items = Arr::get($block, 'data.items');

        if (! is_array($items) || $items === []) {
            return '';
        }

        $tag = Arr::get($block, 'data.style') === 'ordered' ? 'ol' : 'ul';
        $baseClass = $tag === 'ol' ? 'list-decimal' : 'list-disc';
        $listItems = collect($items)
            ->map(fn ($item) => $this->sanitizeInline(is_string($item) ? $item : null))
            ->filter()
            ->map(fn (string $item) => sprintf('<li class="pl-1">%s</li>', e($item)))
            ->implode('');

        if ($listItems === '') {
            return '';
        }

        return sprintf(
            '<%1$s class="%2$s ml-6 mb-4 space-y-2 text-base-content">%3$s</%1$s>',
            $tag,
            $baseClass,
            $listItems
        );
    }

    /**
     * @param  array<string, mixed>  $block
     */
    protected function renderQuote(array $block): string
    {
        $text = $this->sanitizeMultiline(Arr::get($block, 'data.text'));

        if ($text === null) {
            return '';
        }

        $caption = $this->sanitizeInline(Arr::get($block, 'data.caption'));
        $quote = sprintf(
            '<blockquote class="italic text-lg leading-relaxed">%s</blockquote>',
            nl2br(e($text))
        );

        if ($caption !== null) {
            $quote .= sprintf(
                '<figcaption class="mt-3 text-sm text-base-content/70">%s</figcaption>',
                e($caption)
            );
        }

        return sprintf('<figure class="my-6 border-l-4 border-primary/40 pl-5">%s</figure>', $quote);
    }

    /**
     * @param  array<string, mixed>  $block
     */
    protected function renderImage(array $block): string
    {
        $url = $this->resolveImageUrl($block);

        if ($url === null) {
            return '';
        }

        $caption = $this->sanitizeInline(Arr::get($block, 'data.caption'));
        $alt = $this->sanitizeInline(Arr::get($block, 'data.alt')) ?? $caption ?? 'Embedded illustration';

        $figure = sprintf(
            '<img src="%s" alt="%s" loading="lazy" class="mx-auto max-h-[480px] w-full rounded-2xl object-contain shadow-lg">',
            e($url),
            e($alt)
        );

        if ($caption !== null) {
            $figure .= sprintf(
                '<figcaption class="mt-3 text-center text-sm text-base-content/70">%s</figcaption>',
                e($caption)
            );
        }

        return sprintf('<figure class="my-8">%s</figure>', $figure);
    }

    /**
     * @param  array<string, mixed>  $block
     */
    protected function renderEmbed(array $block): string
    {
        $url = $this->sanitizeInline(Arr::get($block, 'data.url'));

        if ($url === null || ! $this->isAllowedEmbedUrl($url)) {
            return '';
        }

        $caption = $this->sanitizeInline(Arr::get($block, 'data.caption'));
        $iframe = sprintf(
            '<iframe src="%s" class="h-full w-full" loading="lazy" allow="accelerometer; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" sandbox="allow-same-origin allow-scripts allow-presentation" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen title="Embedded video"></iframe>',
            e($url)
        );

        $content = sprintf('<div class="ratio ratio-16x9 overflow-hidden rounded-2xl shadow-lg">%s</div>', $iframe);

        if ($caption !== null) {
            $content .= sprintf(
                '<p class="mt-3 text-center text-sm text-base-content/70">%s</p>',
                e($caption)
            );
        }

        return sprintf('<div class="my-6">%s</div>', $content);
    }

    /**
     * Attempt to resolve a valid image URL from the provided block.
     *
     * @param  array<string, mixed>  $block
     */
    protected function resolveImageUrl(array $block): ?string
    {
        $path = Arr::get($block, 'data.path');

        if (is_string($path) && ($normalized = $this->sanitizePath($path)) !== null) {
            if ($this->isAllowedImagePath($normalized)) {
                return Storage::disk('public')->url($normalized);
            }

            return null;
        }

        $candidates = [
            Arr::get($block, 'data.url'),
            Arr::get($block, 'data.file.url'),
        ];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $normalized = trim($candidate);

            if ($normalized === '') {
                continue;
            }

            if ($this->isAllowedImageUrl($normalized)) {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * Ensure the provided storage path points to an approved directory.
     */
    protected function isAllowedImagePath(string $path): bool
    {
        if (str_contains($path, '..')) {
            return false;
        }

        return Str::startsWith($path, 'custom-pages/');
    }

    /**
     * Determine whether an absolute image URL belongs to our allowed storage hosts.
     */
    protected function isAllowedImageUrl(string $url): bool
    {
        if (Str::startsWith($url, self::IMAGE_STORAGE_PREFIX)) {
            return true;
        }

        $parsed = parse_url($url);

        if ($parsed === false || ! isset($parsed['scheme'], $parsed['host'])) {
            return false;
        }

        if (strtolower($parsed['scheme']) !== 'https') {
            return false;
        }

        $path = '/' . ltrim($parsed['path'] ?? '', '/');

        if (! Str::startsWith($path, [self::IMAGE_STORAGE_PREFIX, self::CUSTOM_IMAGE_ROUTE_PREFIX])) {
            return false;
        }

        if ($this->allowedImageHosts === []) {
            return true;
        }

        return in_array(strtolower($parsed['host']), $this->allowedImageHosts, true);
    }

    /**
     * Validate that the embed URL is a permitted YouTube embed.
     */
    protected function isAllowedEmbedUrl(string $url): bool
    {
        $parsed = parse_url($url);

        if ($parsed === false || ! isset($parsed['scheme'], $parsed['host'], $parsed['path'])) {
            return false;
        }

        if (strtolower($parsed['scheme']) !== 'https') {
            return false;
        }

        $host = strtolower($parsed['host']);

        if (! Str::endsWith($host, 'youtube.com')) {
            return false;
        }

        $segments = array_values(array_filter(explode('/', trim((string) $parsed['path'], '/'))));

        if (count($segments) < 2 || $segments[0] !== 'embed') {
            return false;
        }

        $videoId = $segments[1];

        if (! preg_match('/^[A-Za-z0-9_-]{11}$/', $videoId)) {
            return false;
        }

        if (isset($parsed['query'])) {
            parse_str($parsed['query'], $params);

            $allowedKeys = ['start'];

            foreach ($params as $key => $value) {
                if (! in_array($key, $allowedKeys, true)) {
                    return false;
                }

                if (! $this->isValidStartOffset($value)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Normalize sanitized inline text.
     */
    protected function sanitizeInline(mixed $value): ?string
    {
        $text = $this->sanitizeMultiline($value);

        if ($text === null) {
            return null;
        }

        $collapsed = preg_replace('/\s+/u', ' ', $text);

        if (! is_string($collapsed)) {
            return $text;
        }

        $normalized = trim($collapsed);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * Normalize multiline text while preserving intentional line breaks.
     */
    protected function sanitizeMultiline(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $stripped = strip_tags($value);
        $normalized = trim((string) preg_replace('/\r\n?/', "\n", $stripped));

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @return array<int, string>
     */
    protected function resolveAllowedImageHosts(): array
    {
        $hosts = [];

        $appUrl = config('app.url');

        if (is_string($appUrl)) {
            $host = parse_url($appUrl, PHP_URL_HOST);

            if (is_string($host) && $host !== '') {
                $hosts[] = strtolower($host);
            }
        }

        $publicUrl = config('filesystems.disks.public.url');

        if (is_string($publicUrl)) {
            $host = parse_url($publicUrl, PHP_URL_HOST);

            if (is_string($host) && $host !== '') {
                $hosts[] = strtolower($host);
            }
        }

        return array_values(array_unique(array_filter($hosts)));
    }

    /**
     * Determine whether the provided embed start offset value is valid.
     */
    protected function isValidStartOffset(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_numeric($value)) {
            return (int) $value >= 0;
        }

        if (! is_string($value)) {
            return false;
        }

        if ($value === '') {
            return false;
        }

        if (preg_match('/^(\d+)h(\d+)m(\d+)s$/', $value)) {
            return true;
        }

        return ctype_digit($value);
    }

    /**
     * Clean up relative storage paths before validation.
     */
    protected function sanitizePath(string $path): ?string
    {
        $trimmed = trim($path);

        if ($trimmed === '') {
            return null;
        }

        return ltrim($trimmed, '/');
    }
}
