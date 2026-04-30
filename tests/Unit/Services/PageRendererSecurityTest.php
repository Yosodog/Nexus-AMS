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

    public function test_render_raw_html_preserves_safe_admin_formatting_and_strips_dangerous_output(): void
    {
        $html = (new PageRenderer)->render([
            'html' => <<<'HTML'
                <h2 class="ck-heading_heading2" onclick="alert(1)">Apply Today</h2>
                <script>alert(1)</script>
                <p style="color: red; background-image: url(javascript:alert(1)); text-align: center">
                    <a href="javascript:alert(1)">Bad</a>
                    <a href="/apply" target="_blank">Safe</a>
                </p>
                <figure class="table"><table><tbody><tr><td colspan="2">Details</td></tr></tbody></table></figure>
                <iframe src="https://evil.example/embed/1"></iframe>
                <iframe src="https://www.youtube.com/embed/abc123" onload="alert(1)" allowfullscreen></iframe>
            HTML,
        ]);

        $this->assertStringContainsString('class="ck-heading_heading2"', $html);
        $this->assertStringContainsString('<table>', $html);
        $this->assertStringContainsString('colspan="2"', $html);
        $this->assertStringContainsString('color: red', $html);
        $this->assertStringContainsString('text-align: center', $html);
        $this->assertStringContainsString('href="/apply"', $html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
        $this->assertStringContainsString('https://www.youtube.com/embed/abc123', $html);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('onclick=', $html);
        $this->assertStringNotContainsString('onload=', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('evil.example', $html);
        $this->assertSame(1, substr_count($html, '<iframe '));
    }
}
