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
        $blocks = [
            [
                'type' => 'header',
                'data' => [
                    'text' => 'Apply to Join Nexus',
                    'level' => 2,
                ],
            ],
            [
                'type' => 'paragraph',
                'data' => [
                    'text' => 'Tell prospective members about your alliance and how to get in touch.',
                ],
            ],
            [
                'type' => 'list',
                'data' => [
                    'style' => 'unordered',
                    'items' => [
                        'Share the minimum requirements for applicants.',
                        'Explain how long the review process usually takes.',
                        'Provide a Discord or in-game contact for follow-up questions.',
                    ],
                ],
            ],
        ];

        $page = Page::query()->firstOrCreate(
            ['slug' => 'apply'],
            [
                'status' => Page::STATUS_PUBLISHED,
                'draft' => $blocks,
                'published' => $blocks,
            ]
        );

        if (! $page->wasRecentlyCreated) {
            return;
        }

        /** @var PageRenderer $renderer */
        $renderer = app(PageRenderer::class);
        $html = $renderer->render($blocks);

        $page->forceFill(['cached_html' => $html])->save();

        $page->versions()->create([
            'editor_state' => $blocks,
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
