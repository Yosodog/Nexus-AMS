<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Render HTML content for CMS pages while preserving legacy Editor.js blocks.
 */
class PageRenderer
{
    /**
     * @var array<string, bool>
     */
    private const ALLOWED_TAGS = [
        'a' => true,
        'abbr' => true,
        'b' => true,
        'blockquote' => true,
        'br' => true,
        'caption' => true,
        'code' => true,
        'col' => true,
        'colgroup' => true,
        'del' => true,
        'div' => true,
        'em' => true,
        'figcaption' => true,
        'figure' => true,
        'h1' => true,
        'h2' => true,
        'h3' => true,
        'h4' => true,
        'h5' => true,
        'h6' => true,
        'hr' => true,
        'i' => true,
        'iframe' => true,
        'img' => true,
        'input' => true,
        'label' => true,
        'li' => true,
        'mark' => true,
        'oembed' => true,
        'ol' => true,
        'p' => true,
        'pre' => true,
        's' => true,
        'small' => true,
        'span' => true,
        'strong' => true,
        'sub' => true,
        'sup' => true,
        'table' => true,
        'tbody' => true,
        'td' => true,
        'tfoot' => true,
        'th' => true,
        'thead' => true,
        'tr' => true,
        'u' => true,
        'ul' => true,
    ];

    /**
     * @var array<string, bool>
     */
    private const DROP_WITH_CONTENT_TAGS = [
        'base' => true,
        'applet' => true,
        'audio' => true,
        'button' => true,
        'canvas' => true,
        'embed' => true,
        'form' => true,
        'frame' => true,
        'frameset' => true,
        'link' => true,
        'math' => true,
        'meta' => true,
        'object' => true,
        'script' => true,
        'select' => true,
        'style' => true,
        'svg' => true,
        'template' => true,
        'textarea' => true,
        'video' => true,
    ];

    /**
     * @var array<string, array<int, string>>
     */
    private const TAG_ATTRIBUTES = [
        'a' => ['href', 'target', 'rel'],
        'col' => ['span', 'width'],
        'iframe' => ['src', 'title', 'width', 'height', 'allow', 'allowfullscreen', 'loading', 'referrerpolicy', 'frameborder'],
        'img' => ['src', 'alt', 'width', 'height', 'loading'],
        'input' => ['type', 'checked', 'disabled'],
        'li' => ['value'],
        'oembed' => ['url'],
        'ol' => ['start', 'type'],
        'td' => ['colspan', 'rowspan'],
        'th' => ['colspan', 'rowspan', 'scope'],
    ];

    /**
     * @var array<int, string>
     */
    private const GLOBAL_ATTRIBUTES = [
        'aria-label',
        'aria-labelledby',
        'aria-describedby',
        'class',
        'id',
        'role',
        'style',
        'title',
    ];

    /**
     * Render the stored payload into HTML.
     *
     * @param  array<int, mixed>|string  $content
     */
    public function render(array|string $content): string
    {
        if (is_array($content)) {
            if (array_key_exists('html', $content) && is_string($content['html'])) {
                return $this->sanitizeHtml($content['html']);
            }

            return $this->renderLegacyBlocks($content);
        }

        return $this->sanitizeHtml($content);
    }

    private function sanitizeHtml(string $html): string
    {
        $normalized = trim($html);

        if ($normalized === '') {
            return '';
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        $document->loadHTML(
            '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body><div id="nexus-page-render-root">'.$normalized.'</div></body></html>',
            LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById('nexus-page-render-root');

        if (! $root instanceof DOMElement) {
            return '';
        }

        $this->sanitizeNode($root);

        return trim($this->innerHtml($root));
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

    private function sanitizeNode(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if (! $child instanceof DOMElement) {
                if ($child->nodeType === XML_COMMENT_NODE) {
                    $node->removeChild($child);
                }

                continue;
            }

            $tag = strtolower($child->tagName);

            if (! isset(self::ALLOWED_TAGS[$tag])) {
                if (isset(self::DROP_WITH_CONTENT_TAGS[$tag])) {
                    $node->removeChild($child);
                } else {
                    $this->sanitizeNode($child);
                    $this->unwrapElement($child);
                }

                continue;
            }

            $this->sanitizeElement($child, $tag);
            $this->sanitizeNode($child);

            if ($this->shouldRemoveAfterSanitizing($child, $tag)) {
                $child->parentNode?->removeChild($child);
            }
        }
    }

    private function sanitizeElement(DOMElement $element, string $tag): void
    {
        foreach (iterator_to_array($element->attributes) as $attribute) {
            $name = strtolower($attribute->name);
            $value = $attribute->value;

            if (str_starts_with($name, 'on') || ! $this->isAllowedAttribute($tag, $name)) {
                $element->removeAttributeNode($attribute);

                continue;
            }

            $sanitizedValue = $this->sanitizeAttributeValue($tag, $name, $value);

            if ($sanitizedValue === null) {
                $element->removeAttributeNode($attribute);

                continue;
            }

            $element->setAttribute($name, $sanitizedValue);
        }

        if ($tag === 'a' && $element->getAttribute('target') === '_blank') {
            $rel = collect(explode(' ', strtolower($element->getAttribute('rel'))))
                ->filter()
                ->merge(['noopener', 'noreferrer'])
                ->unique()
                ->implode(' ');

            $element->setAttribute('rel', $rel);
        }

        if ($tag === 'input') {
            $element->setAttribute('type', 'checkbox');
            $element->setAttribute('disabled', 'disabled');
        }
    }

    private function sanitizeAttributeValue(string $tag, string $name, string $value): ?string
    {
        $decoded = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($decoded === '' && ! in_array($name, ['alt', 'checked', 'disabled', 'allowfullscreen'], true)) {
            return null;
        }

        if ($name === 'style') {
            return $this->sanitizeStyle($decoded);
        }

        if ($tag === 'a' && $name === 'href') {
            return $this->isSafeUrl($decoded) ? $decoded : null;
        }

        if ($tag === 'img' && $name === 'src') {
            return $this->normalizeImageSource($decoded);
        }

        if ($tag === 'iframe' && $name === 'src') {
            return $this->normalizeEmbedSource($decoded);
        }

        if ($tag === 'oembed' && $name === 'url') {
            return $this->normalizeEmbedSource($decoded);
        }

        if ($tag === 'input' && $name === 'type') {
            return strtolower($decoded) === 'checkbox' ? 'checkbox' : null;
        }

        if ($name === 'target') {
            return in_array($decoded, ['_blank', '_self'], true) ? $decoded : null;
        }

        if ($name === 'loading') {
            return in_array($decoded, ['lazy', 'eager'], true) ? $decoded : null;
        }

        if ($name === 'referrerpolicy') {
            return in_array($decoded, ['no-referrer', 'strict-origin', 'strict-origin-when-cross-origin'], true) ? $decoded : null;
        }

        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', $decoded)) {
            return null;
        }

        return $decoded;
    }

    private function sanitizeStyle(string $style): ?string
    {
        $declarations = [];

        foreach (explode(';', $style) as $declaration) {
            if (! str_contains($declaration, ':')) {
                continue;
            }

            [$property, $value] = array_map('trim', explode(':', $declaration, 2));
            $property = strtolower($property);
            $normalizedValue = strtolower(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            if ($property === '' || $value === '') {
                continue;
            }

            if (str_starts_with($property, 'behavior') || $property === '-moz-binding') {
                continue;
            }

            if (Str::contains($normalizedValue, ['url(', 'expression', 'javascript:', 'vbscript:', 'data:', '@import'])) {
                continue;
            }

            $declarations[] = "{$property}: {$value}";
        }

        return $declarations === [] ? null : implode('; ', $declarations);
    }

    private function isAllowedAttribute(string $tag, string $name): bool
    {
        if (in_array($name, self::GLOBAL_ATTRIBUTES, true)) {
            return true;
        }

        if (str_starts_with($name, 'aria-') || str_starts_with($name, 'data-')) {
            return true;
        }

        return in_array($name, self::TAG_ATTRIBUTES[$tag] ?? [], true);
    }

    private function shouldRemoveAfterSanitizing(DOMElement $element, string $tag): bool
    {
        return ($tag === 'img' || $tag === 'iframe') && ! $element->hasAttribute('src')
            || $tag === 'oembed' && ! $element->hasAttribute('url');
    }

    private function isSafeUrl(string $url): bool
    {
        if (Str::startsWith($url, ['/', '#']) && ! Str::startsWith($url, '//')) {
            return true;
        }

        return preg_match('#^(https?://|mailto:|tel:)#i', $url) === 1;
    }

    private function unwrapElement(DOMElement $element): void
    {
        $parent = $element->parentNode;

        if (! $parent) {
            return;
        }

        while ($element->firstChild) {
            $parent->insertBefore($element->firstChild, $element);
        }

        $parent->removeChild($element);
    }

    private function innerHtml(DOMElement $element): string
    {
        $html = '';

        foreach ($element->childNodes as $child) {
            $html .= $element->ownerDocument?->saveHTML($child) ?? '';
        }

        return $html;
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
