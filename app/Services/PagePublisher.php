<?php

namespace App\Services;

use App\Models\Page;
use App\Models\PageVersion;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Coordinate persistence of Editor.js blocks for CMS pages.
 */
class PagePublisher
{
    /**
     * Save a draft revision for the provided page.
     *
     * @param  array<int, mixed>  $blocks
     */
    public function saveDraft(Page $page, array $blocks, User $user, array $metadata = []): PageVersion
    {
        $this->authorize($user);

        $normalized = $this->normalizeBlocks($blocks);

        return $page->saveDraft($normalized, $user, $metadata);
    }

    /**
     * Publish a set of blocks and store the rendered HTML snapshot.
     *
     * @param  array<int, mixed>  $blocks
     */
    public function publish(Page $page, array $blocks, string $renderedHtml, User $user, ?CarbonInterface $publishedAt = null): PageVersion
    {
        $this->authorize($user);

        $normalized = $this->normalizeBlocks($blocks);

        return $page->publish($normalized, $renderedHtml, $user, $publishedAt);
    }

    /**
     * Restore a historical version either as a draft or published revision.
     */
    public function restore(Page $page, PageVersion $version, User $user, bool $restoreAsDraft = true): void
    {
        $this->authorize($user);

        $page->restoreFromVersion($version, $user, $restoreAsDraft);
    }

    /**
     * Forget cached HTML for the provided page.
     */
    public function forget(Page $page): void
    {
        $page->forgetCachedHtml();
    }

    /**
     * Normalize the Editor.js block payload ensuring embeds and images are valid.
     *
     * @param  array<int, mixed>  $blocks
     * @return array<int, mixed>
     */
    public function normalizeBlocks(array $blocks): array
    {
        $normalized = $this->prepareBlocks($blocks);
        $this->validateImagePaths($normalized);

        return $normalized;
    }

    /**
     * Ensure the acting user has permission to manage custom pages.
     */
    protected function authorize(User $user): void
    {
        Gate::forUser($user)->authorize('manage-custom-pages');
    }

    /**
     * @param  array<int, mixed>  $blocks
     * @return array<int, mixed>
     */
    protected function prepareBlocks(array $blocks): array
    {
        $normalized = $this->canonicalizeEmbeds($blocks);

        return $this->canonicalizeImages($normalized);
    }

    /**
     * Walk each block recursively and normalize supported embed payloads.
     *
     * @param  array<int|string, mixed>  $blocks
     * @return array<int|string, mixed>
     */
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

    /**
     * Normalize image metadata so blocks persist permanent storage URLs.
     *
     * @param  array<int|string, mixed>  $blocks
     * @return array<int|string, mixed>
     */
    protected function canonicalizeImages(array $blocks): array
    {
        $normalized = [];

        foreach ($blocks as $key => $block) {
            if (is_array($block)) {
                if (($block['type'] ?? null) === 'image') {
                    $block['data'] = $this->normalizeImageData($block['data'] ?? []);
                }

                if (isset($block['blocks']) && is_array($block['blocks'])) {
                    $block['blocks'] = $this->canonicalizeImages($block['blocks']);
                }
            }

            $normalized[$key] = $block;
        }

        return $normalized;
    }

    /**
     * Normalize and validate supported embed URLs.
     */
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

    /**
     * @param  array<int|string, mixed>  $blocks
     */
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

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
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

    /**
     * Normalize the stored payload for Editor.js image blocks.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function normalizeImageData(array $data): array
    {
        $path = $this->extractImagePath($data);

        if ($path === null) {
            return $data;
        }

        $publicUrl = Storage::disk('public')->url($path);

        $data['path'] = $path;
        $data['url'] = $publicUrl;

        $file = Arr::get($data, 'file', []);
        if (! is_array($file)) {
            $file = [];
        }

        $file['path'] = $path;
        $file['url'] = $publicUrl;
        $data['file'] = $file;

        return $data;
    }

    /**
     * Extract a persistent storage path from image block data.
     *
     * @param  array<string, mixed>  $data
     */
    protected function extractImagePath(array $data): ?string
    {
        $candidates = [
            Arr::get($data, 'path'),
            Arr::get($data, 'file.path'),
            Arr::get($data, 'file.url'),
            Arr::get($data, 'url'),
            Arr::get($data, 'src'),
        ];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            $resolved = $this->resolveImagePath($candidate);

            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
    }

    protected function resolveImagePath(string $candidate): ?string
    {
        $trimmed = trim($candidate);

        if ($trimmed === '') {
            return null;
        }

        if (Str::startsWith($trimmed, 'custom-pages/')) {
            return $trimmed;
        }

        if (Str::startsWith($trimmed, '/storage/')) {
            return $this->normalizeStoragePath($trimmed);
        }

        if (! Str::startsWith($trimmed, ['http://', 'https://'])) {
            return null;
        }

        $path = parse_url($trimmed, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            return null;
        }

        if (Str::startsWith($path, '/admin/customization/images/')) {
            $token = substr($path, strlen('/admin/customization/images/'));
            if (! is_string($token) || $token === '') {
                return null;
            }

            try {
                $decoded = Crypt::decryptString(urldecode($token));
            } catch (DecryptException) {
                return null;
            }

            return $this->normalizeStoragePath($decoded);
        }

        return $this->normalizeStoragePath($path);
    }

    protected function normalizeStoragePath(string $path): ?string
    {
        $normalized = ltrim(trim($path), '/');

        if ($normalized === '') {
            return null;
        }

        if (Str::startsWith($normalized, 'storage/')) {
            $normalized = substr($normalized, strlen('storage/')) ?: '';
        }

        return Str::startsWith($normalized, 'custom-pages/') ? $normalized : null;
    }

    /**
     * Determine whether the given host belongs to YouTube.
     */
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

    /**
     * Ensure stored image paths originate from an allowed storage location.
     */
    protected function assertValidImagePath(string $path): void
    {
        $trimmed = trim($path);

        if ($trimmed === '') {
            throw new InvalidArgumentException('Image paths cannot be empty.');
        }

        if (Str::startsWith($trimmed, ['custom-pages/', '/storage/', 'https://', 'http://'])) {
            return;
        }

        throw new InvalidArgumentException("Invalid image path provided: {$path}");
    }
}
