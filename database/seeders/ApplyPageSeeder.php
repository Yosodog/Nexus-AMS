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
<p>Tell applicants what kind of alliance they are joining and how to reach you.</p>
<ul>
    <li>List the minimum requirements.</li>
    <li>Say how long reviews usually take.</li>
    <li>Add a Discord or in-game contact for questions.</li>
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
