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

    public function test_normalize_html_sanitizes_raw_html(): void
    {
        $html = (new PageRenderer)->render('<p>Safe</p><script>alert(1)</script><img src=x onerror=alert(2)>');

        $this->assertStringContainsString('<p>Safe</p>', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('onerror=', $html);
    }

    public function test_normalize_html_preserves_utf8_characters(): void
    {
        $html = (new PageRenderer)->render('<p>Hello 🌍 — öäü</p>');

        $this->assertTrue(
            str_contains($html, 'Hello 🌍 — öäü') ||
            str_contains($html, 'Hello &#127757; &mdash; &ouml;&auml;&uuml;') ||
            str_contains($html, 'Hello &#x1F30D; &#x2014; &#xF6;&#xE4;&#xFC;')
        );
        $this->assertStringNotContainsString('<?xml', $html);
    }

    public function test_normalize_html_prevents_skipped_invalid_tags_bypass(): void
    {
        $html = (new PageRenderer)->render('<script><script>alert(1)</script></script>Safe');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('Safe', $html);
    }

    public function test_normalize_html_prevents_obfuscated_protocol_bypass(): void
    {
        $payload = '<a href="java' . "\0" . 'script:alert(1)">Link</a>' .
                   '<a href="javascript' . "\n" . ':alert(2)">Link</a>';

        $html = (new PageRenderer)->render($payload);

        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('java' . "\0" . 'script:', $html);
    }
}
