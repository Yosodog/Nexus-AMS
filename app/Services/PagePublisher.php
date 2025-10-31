<?php

namespace App\Services;

use App\Models\Page;
use App\Models\PageVersion;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Gate;

/**
 * Coordinate persistence of rich text content for CMS pages.
 */
class PagePublisher
{
    /**
     * Save a draft revision for the provided page.
     */
    public function saveDraft(Page $page, string $content, User $user, array $metadata = []): PageVersion
    {
        $this->authorize($user);

        $normalized = $this->normalizeContent($content);

        return $page->saveDraft($normalized, $user, $metadata);
    }

    /**
     * Publish content and store the rendered HTML snapshot.
     */
    public function publish(Page $page, string $content, string $renderedHtml, User $user, ?CarbonInterface $publishedAt = null): PageVersion
    {
        $this->authorize($user);

        $normalized = $this->normalizeContent($content);

        return $page->publish($normalized, $renderedHtml, $user, $publishedAt);
    }

    /**
     * Restore a historical version either as a draft or published revision.
     */
    public function restore(Page $page, PageVersion $version, User $user, bool $restoreAsDraft = true): void
    {
        $this->authorize($user);

        $content = $this->normalizeContent($version->editor_state ?? '');

        $page->restoreFromVersion($version, $user, $restoreAsDraft, $content);
    }

    /**
     * Forget cached HTML for the provided page.
     */
    public function forget(Page $page): void
    {
        $page->forgetCachedHtml();
    }

    /**
     * Trim editor content and normalize line endings.
     */
    public function normalizeContent(string $content): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $content);

        return trim($normalized);
    }

    /**
     * Ensure the acting user has permission to manage custom pages.
     */
    protected function authorize(User $user): void
    {
        Gate::forUser($user)->authorize('manage-custom-pages');
    }
}
