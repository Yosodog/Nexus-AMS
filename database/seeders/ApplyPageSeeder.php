<?php

namespace Database\Seeders;

use App\Models\Page;
use App\Models\PageActivityLog;
use App\Models\PageVersion;
use App\Services\PageRenderer;
use Illuminate\Database\Seeder;

/**
 * Seed the "apply" custom page with a basic published draft so admins can customize it.
 */
class ApplyPageSeeder extends Seeder
{
    /**
     * Seed the default application page if it does not exist yet.
     */
    public function run(): void
    {
        $content = <<<'HTML'
<h2>Apply to Join Nexus</h2>
<p>Tell prospective members about your alliance and how to get in touch.</p>
<ul>
    <li>Share the minimum requirements for applicants.</li>
    <li>Explain how long the review process usually takes.</li>
    <li>Provide a Discord or in-game contact for follow-up questions.</li>
</ul>
HTML;

        $page = Page::query()->firstOrCreate(
            ['slug' => 'apply'],
            [
                'status' => Page::STATUS_PUBLISHED,
                'draft' => $content,
                'published' => $content,
            ]
        );

        if (! $page->wasRecentlyCreated) {
            return;
        }

        /** @var PageRenderer $renderer */
        $renderer = app(PageRenderer::class);
        $html = $renderer->render($content);

        $page->forceFill(['cached_html' => $html])->save();

        $page->versions()->create([
            'editor_state' => $content,
            'status' => PageVersion::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        $page->activityLogs()->create([
            'action' => PageActivityLog::ACTION_PUBLISHED,
            'metadata' => [
                'seeded' => true,
            ],
        ]);
    }
}
