<?php

namespace Tests\Unit\Services;

use App\Services\PageRenderer;
use Tests\UnitTestCase;

class PageRendererSecurityTest extends UnitTestCase
{
    public function test_render_legacy_blocks_strip_tags_and_escape_output(): void
    {
        $html = (new PageRenderer)->render([
            [
                'type' => 'paragraph',
                'data' => [
                    'text' => '<script>alert(1)</script><b>Safe</b>',
                ],
            ],
            [
                'type' => 'list',
                'data' => [
                    'items' => ['<img src=x onerror=alert(1)>Item'],
                ],
            ],
        ]);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('onerror=', $html);
        $this->assertStringContainsString('alert(1)Safe', $html);
        $this->assertStringContainsString('<li class="pl-1">Item</li>', $html);
    }

    public function test_render_image_blocks_reject_untrusted_sources(): void
    {
        $html = (new PageRenderer)->render([
            [
                'type' => 'image',
                'data' => [
                    'file' => ['url' => 'javascript:alert(1)'],
                ],
            ],
            [
                'type' => 'image',
                'data' => [
                    'file' => ['url' => '/storage/uploads/example.png'],
                    'caption' => 'Allowed',
                ],
            ],
        ]);

        $this->assertStringNotContainsString('javascript:alert(1)', $html);
        $this->assertStringContainsString('/storage/uploads/example.png', $html);
        $this->assertSame(1, substr_count($html, '<img '));
    }

    public function test_render_embed_blocks_only_allow_youtube_hosts(): void
    {
        $html = (new PageRenderer)->render([
            [
                'type' => 'embed',
                'data' => [
                    'embed' => 'https://evil.example/embed/123',
                ],
            ],
            [
                'type' => 'embed',
                'data' => [
                    'embed' => 'https://www.youtube.com/embed/abc123',
                    'caption' => 'Video',
                ],
            ],
        ]);

        $this->assertStringNotContainsString('evil.example', $html);
        $this->assertStringContainsString('https://www.youtube.com/embed/abc123', $html);
        $this->assertSame(1, substr_count($html, '<iframe '));
    }

    public function test_normalize_html_strips_dangerous_tags(): void
    {
        $renderer = new PageRenderer;

        $malicious = '<p onmouseover="alert(1)">Safe</p><script>alert(1)</script><iframe src="https://youtube.com/embed/123"></iframe><div onmouseover="alert(1)">Evil</div><a href="javascript:alert(1)">Link</a>';
        $result = $renderer->render($malicious);

        $this->assertStringContainsString('<p>Safe</p>', $result);
        $this->assertStringContainsString('<iframe src="https://youtube.com/embed/123"></iframe>', $result);
        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringNotContainsString('<div>', $result);
        $this->assertStringNotContainsString('onmouseover', $result);
        $this->assertStringNotContainsString('javascript:', $result);
        $this->assertStringContainsString('Evil', $result);
        $this->assertStringContainsString('<a>Link</a>', $result);
    }
}
