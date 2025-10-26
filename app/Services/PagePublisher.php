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

        $normalized = $this->normalizeBlocks($blocks);

        return $page->saveDraft($normalized, $user, $metadata);
    }

    public function publish(Page $page, array $blocks, string $renderedHtml, User $user, ?CarbonInterface $publishedAt = null): PageVersion
    {
        $this->authorize($user);

        $normalized = $this->normalizeBlocks($blocks);

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

    public function normalizeBlocks(array $blocks): array
    {
        $normalized = $this->prepareBlocks($blocks);
        $this->validateImagePaths($normalized);

        return $normalized;
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
                    $block['data'] = $this->normalizeEmbedData($block['data'] ?? []);
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
            throw new InvalidArgumentException('Embed URLs must not be empty.');
        }

        $parsed = parse_url($trimmed);

        if ($parsed === false || empty($parsed['host'])) {
            throw new InvalidArgumentException('Unsupported embed URL.');
        }

        $host = strtolower($parsed['host']);

        if (! $this->isYouTubeHost($host)) {
            throw new InvalidArgumentException('Only YouTube embeds are supported.');
        }

        $videoId = $this->extractYouTubeId($host, $parsed);

        if ($videoId === null) {
            throw new InvalidArgumentException('Unable to determine the YouTube video identifier.');
        }

        $start = $this->extractStartOffset($parsed);
        $query = $start !== null ? '?start=' . $start : '';

        return sprintf('https://www.youtube.com/embed/%s%s', $videoId, $query);
    }

    protected function validateImagePaths(array $blocks): void
    {
        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }

            if (($block['type'] ?? null) === 'image') {
                $source = Arr::get($block, 'data.src')
                    ?? Arr::get($block, 'data.path')
                    ?? Arr::get($block, 'data.file.url');

                if (is_string($source) && $source !== '') {
                    $this->assertValidImagePath($source);
                }
            }

            if (isset($block['blocks']) && is_array($block['blocks'])) {
                $this->validateImagePaths($block['blocks']);
            }
        }
    }

    protected function normalizeEmbedData(array $data): array
    {
        $url = Arr::get($data, 'url') ?? Arr::get($data, 'source') ?? Arr::get($data, 'embed');

        if (! is_string($url) || trim($url) === '') {
            throw new InvalidArgumentException('Embed URLs are required.');
        }

        $caption = Arr::get($data, 'caption');

        $normalized = [
            'url' => $this->canonicalizeUrl($url),
        ];

        if (is_string($caption) && $caption !== '') {
            $normalized['caption'] = $caption;
        }

        return $normalized;
    }

    protected function isYouTubeHost(string $host): bool
    {
        return $host === 'youtu.be'
            || Str::endsWith($host, '.youtube.com')
            || $host === 'youtube.com';
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    protected function extractYouTubeId(string $host, array $parsed): ?string
    {
        $path = $parsed['path'] ?? '';

        if ($host === 'youtu.be') {
            $candidate = ltrim($path, '/');
        } elseif (Str::endsWith($host, 'youtube.com')) {
            $candidate = $this->extractFromYouTubePath($path, $parsed['query'] ?? '');
        } else {
            $candidate = null;
        }

        if (! is_string($candidate) || $candidate === '') {
            return null;
        }

        $candidate = trim($candidate);

        if (! preg_match('/^[A-Za-z0-9_-]{11}$/', $candidate)) {
            return null;
        }

        return $candidate;
    }

    protected function extractFromYouTubePath(string $path, ?string $query): ?string
    {
        $normalizedPath = trim($path) ?: '/';

        if (Str::startsWith($normalizedPath, '/embed/')) {
            $value = substr($normalizedPath, strlen('/embed/')) ?: null;

            return $value !== null ? strtok($value, '/') ?: null : null;
        }

        if (Str::startsWith($normalizedPath, '/shorts/')) {
            $value = substr($normalizedPath, strlen('/shorts/')) ?: null;

            return $value !== null ? strtok($value, '/') ?: null : null;
        }

        if (Str::startsWith($normalizedPath, '/v/')) {
            $value = substr($normalizedPath, strlen('/v/')) ?: null;

            return $value !== null ? strtok($value, '/') ?: null : null;
        }

        if (Str::startsWith($normalizedPath, '/live/')) {
            $value = substr($normalizedPath, strlen('/live/')) ?: null;

            return $value !== null ? strtok($value, '/') ?: null : null;
        }

        if ($normalizedPath === '/' || Str::startsWith($normalizedPath, '/watch')) {
            parse_str($query ?? '', $params);

            if (isset($params['v']) && is_string($params['v'])) {
                return $params['v'];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    protected function extractStartOffset(array $parsed): ?int
    {
        $query = $parsed['query'] ?? '';

        if ($query === '') {
            return null;
        }

        parse_str($query, $params);

        $raw = $params['start'] ?? $params['t'] ?? null;

        if ($raw === null) {
            return null;
        }

        if (is_numeric($raw)) {
            return max(0, (int) $raw);
        }

        if (is_string($raw) && preg_match('/^(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?$/', $raw, $matches)) {
            $hours = isset($matches[1]) ? (int) $matches[1] : 0;
            $minutes = isset($matches[2]) ? (int) $matches[2] : 0;
            $seconds = isset($matches[3]) ? (int) $matches[3] : 0;

            return ($hours * 3600) + ($minutes * 60) + $seconds;
        }

        return null;
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
