<?php

namespace App\Services;

use Illuminate\Support\Arr;

/**
 * Render sanitized HTML fragments from Editor.js block payloads.
 */
class PageRenderer
{
    /**
     * @param  array<int, mixed>  $blocks
     */
    public function render(array $blocks): string
    {
        return collect($blocks)
            ->map(function ($block) {
                if (! is_array($block)) {
                    return '';
                }

                return $this->renderBlock($block);
            })
            ->filter()
            ->implode(PHP_EOL);
    }

    /**
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
        $text = (string) Arr::get($block, 'data.text', '');

        if ($text === '') {
            return '';
        }

        return sprintf('<p class="mb-3">%s</p>', nl2br(e($text)));
    }

    /**
     * @param  array<string, mixed>  $block
     */
    protected function renderHeader(array $block): string
    {
        $level = (int) Arr::get($block, 'data.level', 2);
        $level = $level >= 1 && $level <= 6 ? $level : 2;
        $text = trim((string) Arr::get($block, 'data.text', ''));

        if ($text === '') {
            return '';
        }

        return sprintf('<h%d class="fw-semibold mt-4 mb-3">%s</h%d>', $level, e($text), $level);
    }

    /**
     * @param  array<string, mixed>  $block
     */
    protected function renderList(array $block): string
    {
        $items = Arr::get($block, 'data.items', []);

        if (! is_array($items) || $items === []) {
            return '';
        }

        $style = Arr::get($block, 'data.style', 'unordered') === 'ordered' ? 'ol' : 'ul';
        $listItems = collect($items)
            ->map(function ($item) {
                $content = trim((string) $item);

                return $content === '' ? '' : sprintf('<li>%s</li>', e($content));
            })
            ->filter()
            ->implode('');

        if ($listItems === '') {
            return '';
        }

        $classes = 'ps-4 mb-3';

        return sprintf('<%1$s class="%2$s">%3$s</%1$s>', $style, $classes, $listItems);
    }

    /**
     * @param  array<string, mixed>  $block
     */
    protected function renderQuote(array $block): string
    {
        $text = trim((string) Arr::get($block, 'data.text', ''));

        if ($text === '') {
            return '';
        }

        $caption = trim((string) Arr::get($block, 'data.caption', ''));
        $quote = sprintf('<blockquote class="mb-2">%s</blockquote>', nl2br(e($text)));

        if ($caption !== '') {
            $quote .= sprintf('<figcaption class="text-muted small">%s</figcaption>', e($caption));
        }

        return sprintf('<figure class="border-start border-3 ps-3 my-4">%s</figure>', $quote);
    }

    /**
     * @param  array<string, mixed>  $block
     */
    protected function renderImage(array $block): string
    {
        $url = Arr::get($block, 'data.url')
            ?? Arr::get($block, 'data.file.url')
            ?? Arr::get($block, 'data.path');

        if (! is_string($url) || trim($url) === '') {
            return '';
        }

        $caption = trim((string) Arr::get($block, 'data.caption', ''));
        $alt = trim((string) Arr::get($block, 'data.alt', $caption));
        $figure = sprintf(
            '<img src="%s" alt="%s" loading="lazy" class="img-fluid rounded shadow-sm">',
            e($url),
            e($alt)
        );

        if ($caption !== '') {
            $figure .= sprintf('<figcaption class="text-muted small mt-2">%s</figcaption>', e($caption));
        }

        return sprintf('<figure class="my-4 text-center">%s</figure>', $figure);
    }

    /**
     * @param  array<string, mixed>  $block
     */
    protected function renderEmbed(array $block): string
    {
        $url = trim((string) Arr::get($block, 'data.url', ''));

        if ($url === '') {
            return '';
        }

        $caption = trim((string) Arr::get($block, 'data.caption', ''));
        $iframe = sprintf(
            '<iframe src="%s" loading="lazy" allow="accelerometer; encrypted-media; fullscreen" sandbox="allow-same-origin allow-scripts allow-presentation" allowfullscreen referrerpolicy="strict-origin-when-cross-origin" title="Embedded media"></iframe>',
            e($url)
        );

        $content = sprintf('<div class="ratio ratio-16x9 rounded overflow-hidden shadow-sm">%s</div>', $iframe);

        if ($caption !== '') {
            $content .= sprintf('<div class="text-muted small mt-2 text-center">%s</div>', e($caption));
        }

        return sprintf('<div class="my-4">%s</div>', $content);
    }
}
