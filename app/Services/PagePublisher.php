<?php

namespace App\Services;

use App\Models\Page;
use App\Models\PageVersion;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

class PagePublisher
{
    public function saveDraft(Page $page, array $blocks, User $user, array $metadata = []): PageVersion
    {
        $this->authorize($user);

        $normalized = $this->prepareBlocks($blocks);
        $this->validateImagePaths($normalized);

        return $page->saveDraft($normalized, $user, $metadata);
    }

    public function publish(Page $page, array $blocks, string $renderedHtml, User $user, ?CarbonInterface $publishedAt = null): PageVersion
    {
        $this->authorize($user);

        $normalized = $this->prepareBlocks($blocks);
        $this->validateImagePaths($normalized);

        return $page->publish($normalized, $renderedHtml, $user, $publishedAt);
    }

    public function restore(Page $page, PageVersion $version, User $user, bool $restoreAsDraft = true): void
    {
        $this->authorize($user);

        $page->restoreFromVersion($version, $user, $restoreAsDraft);
    }

    public function forget(Page $page): void
    {
        $page->forgetCachedHtml();
    }

    protected function authorize(User $user): void
    {
        Gate::forUser($user)->authorize('manage-custom-pages');
    }

    protected function prepareBlocks(array $blocks): array
    {
        return $this->canonicalizeEmbeds($blocks);
    }

    protected function canonicalizeEmbeds(array $blocks): array
    {
        $normalized = [];

        foreach ($blocks as $key => $block) {
            if (is_array($block)) {
                if (($block['type'] ?? null) === 'embed') {
                    $url = Arr::get($block, 'data.url');
                    if (is_string($url) && $url !== '') {
                        $block['data']['url'] = $this->canonicalizeUrl($url);
                    }
                }

                if (isset($block['blocks']) && is_array($block['blocks'])) {
                    $block['blocks'] = $this->canonicalizeEmbeds($block['blocks']);
                }
            }

            $normalized[$key] = $block;
        }

        return $normalized;
    }

    protected function canonicalizeUrl(string $url): string
    {
        $trimmed = trim($url);

        if ($trimmed === '') {
            return $trimmed;
        }

        $parsed = parse_url($trimmed);

        if ($parsed === false || empty($parsed['host'])) {
            return $trimmed;
        }

        $scheme = strtolower($parsed['scheme'] ?? 'https');
        $host = strtolower($parsed['host']);
        $path = $parsed['path'] ?? '/';

        $queryString = '';
        if (! empty($parsed['query'])) {
            parse_str($parsed['query'], $queryParams);

            $queryParams = array_filter(
                $queryParams,
                fn ($value, $key) => $value !== '' && ! Str::startsWith(strtolower($key), 'utm_') && strtolower($key) !== 'ref',
                ARRAY_FILTER_USE_BOTH
            );

            if ($queryParams !== []) {
                ksort($queryParams);
                $queryString = '?' . http_build_query($queryParams, '', '&', PHP_QUERY_RFC3986);
            }
        }

        $fragment = isset($parsed['fragment']) ? '#' . $parsed['fragment'] : '';

        return sprintf('%s://%s%s%s%s', $scheme, $host, $path ?: '/', $queryString, $fragment);
    }

    protected function validateImagePaths(array $blocks): void
    {
        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }

            if (($block['type'] ?? null) === 'image') {
                $source = Arr::get($block, 'data.src') ?? Arr::get($block, 'data.path');

                if (is_string($source) && $source !== '') {
                    $this->assertValidImagePath($source);
                }
            }

            if (isset($block['blocks']) && is_array($block['blocks'])) {
                $this->validateImagePaths($block['blocks']);
            }
        }
    }

    protected function assertValidImagePath(string $path): void
    {
        $trimmed = trim($path);

        if ($trimmed === '') {
            throw new InvalidArgumentException('Image paths cannot be empty.');
        }

        if (Str::startsWith($trimmed, ['/storage/', 'https://', 'http://'])) {
            return;
        }

        throw new InvalidArgumentException("Invalid image path provided: {$path}");
    }
}
