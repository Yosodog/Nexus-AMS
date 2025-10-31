<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Render HTML content for CMS pages. Trust CKEditor output while preserving legacy Editor.js blocks.
 */
class PageRenderer
{
    /**
     * Render the stored payload into HTML.
     *
     * @param  array<int, mixed>|string  $content
     */
    public function render(array|string $content): string
    {
        if (is_array($content)) {
            if (array_key_exists('html', $content) && is_string($content['html'])) {
                return $this->normalizeHtml($content['html']);
            }

            return $this->renderLegacyBlocks($content);
        }

        return $this->normalizeHtml($content);
    }

    private function normalizeHtml(string $html): string
    {
        return trim($html);
    }

    /**
     * Render legacy Editor.js blocks for backward compatibility.
     *
     * @param  array<int|string, mixed>  $blocks
     */
    private function renderLegacyBlocks(array $blocks): string
    {
        $fragments = array_map(function ($block) {
            return is_array($block) ? $this->renderLegacyBlock($block) : '';
        }, $blocks);

        $filtered = array_filter($fragments, fn ($html) => is_string($html) && $html !== '');

        return implode(PHP_EOL, $filtered);
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function renderLegacyBlock(array $block): string
    {
        return match ($block['type'] ?? null) {
            'paragraph' => $this->renderParagraph($block),
            'header' => $this->renderHeader($block),
            'list' => $this->renderList($block),
            'quote' => $this->renderQuote($block),
            'image' => $this->renderImage($block),
            'embed' => $this->renderEmbed($block),
            'code' => $this->renderCode($block),
            default => '',
        };
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function renderParagraph(array $block): string
    {
        $text = $this->sanitizeMultiline(Arr::get($block, 'data.text'));

        if ($text === null) {
            return '';
        }

        return sprintf('<p class="mb-4 leading-relaxed">%s</p>', nl2br(e($text)));
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function renderHeader(array $block): string
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
            '<h%d class="mt-8 mb-3 font-semibold %s">%s</h%d>',
            $level,
            $sizeClass,
            e($text),
            $level
        );
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function renderList(array $block): string
    {
        $items = Arr::get($block, 'data.items');

        if (! is_array($items) || $items === []) {
            return '';
        }

        $tag = Arr::get($block, 'data.style') === 'ordered' ? 'ol' : 'ul';
        $baseClass = $tag === 'ol' ? 'list-decimal' : 'list-disc';

        $listItems = array_map(function ($item) {
            $value = $this->sanitizeInline(is_string($item) ? $item : null);

            return $value !== null ? sprintf('<li class="pl-1">%s</li>', e($value)) : '';
        }, $items);

        $listItems = array_filter($listItems, fn ($html) => $html !== '');

        if ($listItems === []) {
            return '';
        }

        return sprintf(
            '<%1$s class="%2$s ps-5 space-y-1">%3$s</%1$s>',
            $tag,
            $baseClass,
            implode('', $listItems)
        );
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function renderQuote(array $block): string
    {
        $text = $this->sanitizeMultiline(Arr::get($block, 'data.text'));

        if ($text === null) {
            return '';
        }

        $caption = $this->sanitizeInline(Arr::get($block, 'data.caption'));

        $quote = sprintf(
            '<blockquote class="border-l-4 border-primary pl-4 py-2 my-4 text-base-content/80"><p class="mb-0">%s</p></blockquote>',
            nl2br(e($text))
        );

        if ($caption === null) {
            return $quote;
        }

        return $quote.sprintf(
            '<p class="ml-4 text-sm text-base-content/60">— %s</p>',
            e($caption)
        );
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function renderImage(array $block): string
    {
        $source = Arr::get($block, 'data.file.url')
            ?? Arr::get($block, 'data.src')
            ?? Arr::get($block, 'data.path');

        if (! is_string($source)) {
            return '';
        }

        $src = $this->normalizeImageSource($source);

        if ($src === null) {
            return '';
        }

        $caption = $this->sanitizeInline(Arr::get($block, 'data.caption'));

        $image = sprintf(
            '<img src="%s" alt="%s" class="max-w-full h-auto rounded shadow-sm" loading="lazy">',
            e($src),
            e($caption ?? 'Illustration')
        );

        if ($caption === null) {
            return sprintf('<figure class="my-6 text-center">%s</figure>', $image);
        }

        return sprintf(
            '<figure class="my-6 text-center">%s<figcaption class="mt-2 text-sm text-base-content/60">%s</figcaption></figure>',
            $image,
            e($caption)
        );
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function renderEmbed(array $block): string
    {
        $url = Arr::get($block, 'data.embed') ?? Arr::get($block, 'data.source') ?? Arr::get($block, 'data.url');

        if (! is_string($url)) {
            return '';
        }

        $src = $this->normalizeEmbedSource($url);

        if ($src === null) {
            return '';
        }

        $caption = $this->sanitizeInline(Arr::get($block, 'data.caption'));

        $iframe = sprintf(
            '<iframe src="%s" title="%s" loading="lazy" allowfullscreen></iframe>',
            e($src),
            e($caption ?? 'Embedded media')
        );

        if ($caption === null) {
            return sprintf('<figure class="media my-6">%s</figure>', $iframe);
        }

        return sprintf(
            '<figure class="media my-6">%s<figcaption class="mt-2 text-sm text-base-content/60">%s</figcaption></figure>',
            $iframe,
            e($caption)
        );
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function renderCode(array $block): string
    {
        $code = Arr::get($block, 'data.code');

        if (! is_string($code) || trim($code) === '') {
            return '';
        }

        return sprintf(
            '<pre class="bg-base-200 rounded p-3 my-4 overflow-auto"><code>%s</code></pre>',
            e($code)
        );
    }

    private function sanitizeInline(?string $value): ?string
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

    private function sanitizeMultiline(?string $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $stripped = strip_tags($value);
        $normalized = preg_replace('/\r\n?/', "\n", $stripped);

        if (! is_string($normalized)) {
            return null;
        }

        $trimmed = trim($normalized);

        return $trimmed === '' ? null : $trimmed;
    }

    private function normalizeImageSource(string $src): ?string
    {
        $trimmed = trim($src);

        if ($trimmed === '') {
            return null;
        }

        if (Str::startsWith($trimmed, ['/storage/', '/admin/customization/images/'])) {
            return $trimmed;
        }

        if (Str::startsWith($trimmed, 'storage/')) {
            return '/'.ltrim($trimmed, '/');
        }

        if (! preg_match('#^https?://#i', $trimmed)) {
            return null;
        }

        return $trimmed;
    }

    private function normalizeEmbedSource(string $src): ?string
    {
        $trimmed = trim($src);

        if ($trimmed === '') {
            return null;
        }

        if (! preg_match('#^https?://#i', $trimmed)) {
            return null;
        }

        $host = strtolower((string) parse_url($trimmed, PHP_URL_HOST));

        if (! $this->isAllowedEmbedHost($host)) {
            return null;
        }

        return $trimmed;
    }

    private function isAllowedEmbedHost(string $host): bool
    {
        return $host === 'youtu.be'
            || Str::endsWith($host, '.youtube.com')
            || $host === 'youtube.com';
    }
}
